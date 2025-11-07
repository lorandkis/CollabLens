import discord
from discord.ext import commands
import logging
from dotenv import load_dotenv

import os
import aiomysql
import asyncio
from permissions import get_announcement_overwrites, get_register_overwrites

load_dotenv()
TOKEN = os.getenv('DISCORD_TOKEN')

# Database connection info
DB_HOST = os.getenv('DB_HOST')
DB_PORT = os.getenv('DB_PORT')
DB_NAME = os.getenv('DB_NAME')
DB_USER = os.getenv('DB_USER')
DB_PASSWORD = os.getenv('DB_PASSWORD')

# Pool size from env (optional)
DB_POOL_MIN = int(os.getenv('DB_POOL_MIN') or 2)
DB_POOL_MAX = int(os.getenv('DB_POOL_MAX') or 20)

db_pool = None
db_pool_conn_kwargs = None
# Message queue for batched DB writes
message_queue = None
# Permission update queue to avoid hitting Discord API rate limits
permission_queue = None
PERM_REQUEST_INTERVAL = float(os.getenv('PERM_REQUEST_INTERVAL') or 0.2)  # seconds between permission requests
PERM_WORKER_MAX_RETRIES = int(os.getenv('PERM_WORKER_MAX_RETRIES') or 5)
MESSAGE_BATCH_SIZE = int(os.getenv('MESSAGE_BATCH_SIZE') or 200)
try:
    MESSAGE_BATCH_INTERVAL = float(os.getenv('MESSAGE_BATCH_INTERVAL') or 0.5)
except Exception:
    MESSAGE_BATCH_INTERVAL = 0.5

handler = logging.FileHandler(filename='discord.log', encoding='utf-8', mode='w')
intents = discord.Intents.default()
intents.message_content = True
intents.members = True
intents.guilds = True


bot = commands.Bot(command_prefix='/', intents=intents)

async def init_db_pool():
    """Attempt to create the asyncpg pool. Returns True on success, False otherwise."""
    global db_pool
    if db_pool is not None:
        return True
    try:
        port = int(DB_PORT) if DB_PORT else 3306
    except Exception:
        port = 3306
    try:
        # aiomysql.create_pool returns a pool of connections
        db_pool = await aiomysql.create_pool(
            host=DB_HOST,
            port=port,
            user=DB_USER,
            password=DB_PASSWORD,
            db=DB_NAME,
            minsize=DB_POOL_MIN,
            maxsize=DB_POOL_MAX
        )
        logging.info("MySQL pool created.")
        return True
    except Exception as e:
        logging.error(f"Failed to create DB pool: {e}")
        db_pool = None
        return False

# Helper to check if a channel is under the group category
def is_group_channel(channel):
    return channel.category and channel.category.name == "📁 Group Channels"
@bot.event
async def on_message(message):
    # Ignore bot's own messages
    if message.author.bot:
        return

    if is_group_channel(message.channel):
        # enqueue the message for batched DB insertion
        global message_queue
        if message_queue is None:
            # lazily create queue
            message_queue = asyncio.Queue()
        try:
            payload = (
                str(message.id),
                str(message.channel.id),
                str(message.author.id),
                message.content,
                message.created_at.strftime('%Y-%m-%d %H:%M:%S')
            )
            # non-blocking put with small timeout
            await message_queue.put(payload)
        except Exception as e:
            logging.error(f"Failed to enqueue message {message.id}: {e}")

    # Allow commands to be processed
    await bot.process_commands(message)

# Helper to get or create the group channels category
async def get_group_category(guild):
    for category in guild.categories:
        if category.name == "📁 Group Channels":
            return category
    return await guild.create_category("📁 Group Channels")

@bot.event
async def on_ready():
    print(f'We have logged in as {bot.user.name}')
    # Try to initialize DB pool at startup
    ok = await init_db_pool()
    if not ok:
        logging.warning("Database pool not initialized at startup. Commands will try to connect on demand.")
    # start background message worker
    global message_queue
    if message_queue is None:
        message_queue = asyncio.Queue()
    bot.loop.create_task(message_worker())
    # start permission worker
    global permission_queue
    if permission_queue is None:
        permission_queue = asyncio.Queue()
    bot.loop.create_task(permission_worker())


async def message_worker():
    """Background task that batches messages from message_queue and writes them to the DB."""
    global message_queue, db_pool
    batch = []
    while True:
        try:
            # wait for first item
            item = await message_queue.get()
            batch.append(item)
            # collect more up to batch size or until timeout
            start = asyncio.get_event_loop().time()
            while len(batch) < MESSAGE_BATCH_SIZE:
                try:
                    timeout = MESSAGE_BATCH_INTERVAL - (asyncio.get_event_loop().time() - start)
                    if timeout <= 0:
                        break
                    item = await asyncio.wait_for(message_queue.get(), timeout=timeout)
                    batch.append(item)
                except asyncio.TimeoutError:
                    break

            # ensure we have a pool
            if db_pool is None:
                ok = await init_db_pool()
                if not ok:
                    logging.error("Message worker: DB pool not available, dropping batch of %d messages", len(batch))
                    batch.clear()
                    await asyncio.sleep(1)
                    continue

            # Build multi-row INSERT
            values_sql = ",".join(["(%s,%s,%s,%s,%s)" for _ in batch])
            params = []
            for row in batch:
                params.extend(row)

            sql = f"INSERT IGNORE INTO discord_messages (message_id, channel_id, author_id, content, timestamp) VALUES {values_sql}"
            try:
                async with db_pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        await cur.execute(sql, params)
                        await conn.commit()
            except Exception as e:
                logging.error(f"Message worker: failed to insert batch: {e}")
            finally:
                batch.clear()
        except Exception as e:
            logging.error(f"Message worker unexpected error: {e}")
            await asyncio.sleep(1)


async def permission_worker():
    """Process permission change requests from `permission_queue` at a controlled rate to avoid hitting API limits.
    Each queue item is a dict: {guild_id, channel_id, user_id, view, send, retries}
    """
    global permission_queue
    while True:
        try:
            item = await permission_queue.get()
            guild_id = item.get('guild_id')
            channel_id = item.get('channel_id')
            user_id = item.get('user_id')
            view = item.get('view', True)
            send = item.get('send', True)
            retries = item.get('retries', 0)

            guild = bot.get_guild(int(guild_id)) if guild_id else None
            if not guild:
                logging.debug(f"Permission worker: guild {guild_id} not found; skipping")
                await asyncio.sleep(PERM_REQUEST_INTERVAL)
                continue

            channel = guild.get_channel(int(channel_id)) if channel_id else None
            if not channel:
                logging.debug(f"Permission worker: channel {channel_id} not found in guild {guild_id}; skipping")
                await asyncio.sleep(PERM_REQUEST_INTERVAL)
                continue

            try:
                member = guild.get_member(int(user_id)) if user_id else None
            except Exception:
                member = None

            if not member:
                # Member may be offline/unavailable via cache; try fetching (costly) as a last resort
                try:
                    member = await guild.fetch_member(int(user_id))
                except Exception:
                    member = None

            if not member:
                logging.debug(f"Permission worker: member {user_id} not found in guild {guild_id}; skipping")
                await asyncio.sleep(PERM_REQUEST_INTERVAL)
                continue

            try:
                await channel.set_permissions(member, view_channel=view, send_messages=send)
            except Exception as e:
                # handle rate limit / transient failures with retry/backoff
                logging.warning(f"Permission worker: failed to set permissions for user {user_id} on channel {channel_id}: {e}")
                if retries < PERM_WORKER_MAX_RETRIES:
                    # exponential backoff
                    backoff = (2 ** retries) * PERM_REQUEST_INTERVAL
                    await asyncio.sleep(backoff)
                    item['retries'] = retries + 1
                    await permission_queue.put(item)
                else:
                    logging.error(f"Permission worker: giving up on setting permissions for user {user_id} on channel {channel_id} after {retries} retries")
            finally:
                # enforce rate limit between requests
                await asyncio.sleep(PERM_REQUEST_INTERVAL)
        except Exception as e:
            logging.error(f"Permission worker unexpected error: {e}")
            await asyncio.sleep(1)


def enqueue_permission(guild_id, channel_id, user_id, view=True, send=True):
    """Synchronous helper to enqueue a permission change request.
    This avoids making permission API calls directly from command handlers.
    """
    global permission_queue
    if permission_queue is None:
        permission_queue = asyncio.Queue()
        try:
            bot.loop.create_task(permission_worker())
        except Exception:
            logging.debug("enqueue_permission: failed to start permission_worker; permission requests may not be processed immediately")
    try:
        permission_queue.put_nowait({
            'guild_id': int(guild_id),
            'channel_id': int(channel_id),
            'user_id': int(user_id),
            'view': bool(view),
            'send': bool(send),
            'retries': 0
        })
    except Exception as e:
        logging.error(f"Failed to enqueue permission change for user {user_id} on channel {channel_id}: {e}")

@bot.command()
@commands.is_owner()
async def createGroups(ctx, assignment_id: int):
    """Create private group channels for an assignment and add registered members."""
    global db_pool
    # Try to initialize DB pool on demand if not already
    if db_pool is None:
        await ctx.send("Database not initialized; attempting to connect...")
        ok = await init_db_pool()
        if not ok:
            await ctx.send("Failed to connect to the database. Check bot logs and your .env settings.")
            return

    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("SELECT title FROM assignments WHERE id = %s", (assignment_id,))
            assignment = await cur.fetchone()
            if not assignment:
                await ctx.send("Assignment not found.")
                return
            await cur.execute("SELECT id, name, discord_channel_id FROM assignment_groups WHERE assignment_id = %s", (assignment_id,))
            groups = await cur.fetchall()
        if not groups:
            await ctx.send("No groups found for this assignment.")
            return

        category = await get_group_category(ctx.guild)
        for group in groups:
            channel_name = f"{assignment['title']}({group['name']})"
            # Check if channel exists by discord_channel_id or name
            channel = None
            if group['discord_channel_id']:
                try:
                    channel = ctx.guild.get_channel(int(group['discord_channel_id']))
                except Exception:
                    channel = None
            if not channel:
                channel = discord.utils.get(category.channels, name=channel_name)

            # Create the channel if missing
            if not channel:
                channel = await ctx.guild.create_text_channel(channel_name, category=category)
                # Update discord_channel_id in DB
                async with conn.cursor() as cur2:
                    await cur2.execute("UPDATE assignment_groups SET discord_channel_id = %s WHERE id = %s", (str(channel.id), group['id']))
                    await conn.commit()

            # Ensure channel overwrites: deny @everyone. We'll grant access to registered members individually.
            try:
                overwrites = {ctx.guild.default_role: discord.PermissionOverwrite(view_channel=False)}
                await channel.edit(overwrites=overwrites)
            except Exception as e:
                logging.debug(f"Failed to set overwrites for channel {channel.name}: {e}")

            # Get registered members for this group and grant them explicit channel permissions
            async with conn.cursor() as cur3:
                await cur3.execute(
                    "SELECT discord_user_id FROM group_members WHERE group_id = %s AND status = 'registered' AND discord_user_id IS NOT NULL",
                    (group['id'],)
                )
                members = await cur3.fetchall()
            for member in members:
                discord_user_id = member['discord_user_id'] if isinstance(member, dict) else member[0]
                try:
                    user = ctx.guild.get_member(int(discord_user_id))
                except Exception:
                    user = None
                if user:
                        try:
                            enqueue_permission(ctx.guild.id, channel.id, int(discord_user_id), view=True, send=True)
                        except Exception as e:
                            logging.error(f"Failed to enqueue channel permission for user {user} on {channel.name}: {e}")
        bot_msg = await ctx.send("Group channels created and members added.")


@bot.command()
async def register(ctx, student_id: str):
    """Register a student and add them to their group channels."""
    global db_pool
    if db_pool is None:
        await ctx.send("Database not initialized; attempting to connect...")
        ok = await init_db_pool()
        if not ok:
            await ctx.send("Failed to connect to the database. Check bot logs and your .env settings.")
            return

    discord_id = str(ctx.author.id)
    discord_username = str(ctx.author)
    async with db_pool.acquire() as conn:
        # Update group_members table for this student
        try:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE group_members gm JOIN students s ON gm.student_id = s.id SET gm.status = 'registered', gm.discord_user_id = %s, gm.discord_username = %s WHERE s.student_id = %s",
                    (discord_id, discord_username, student_id)
                )
                affected = cur.rowcount
                await conn.commit()
            if not affected or affected == 0:
                await ctx.send("Registration did not update any records. Please try again in 10 minutes or contact the instructor.")
                return
        except Exception as e:
            logging.error(f"Registration DB update failed: {e}")
            await ctx.send("Registration failed due to a server error. Please try again in 10 minutes.")
            return

        try:
            async with conn.cursor(aiomysql.DictCursor) as cur2:
                await cur2.execute(
                    """
                    SELECT ag.id, ag.name, ag.discord_channel_id, a.title AS assignment_title
                    FROM assignment_groups ag
                    JOIN group_members gm ON gm.group_id = ag.id
                    JOIN assignments a ON ag.assignment_id = a.id
                    JOIN students s ON gm.student_id = s.id
                    WHERE s.student_id = %s
                    """,
                    (student_id,)
                )
                groups = await cur2.fetchall()
        except Exception as e:
            logging.error(f"Registration group lookup failed: {e}")
            await ctx.send("Registration failed while fetching groups. Please try again in 10 minutes.")
            return

        # Grant the registering user explicit access to each group's channel (no roles used)
        for group in groups:
            # If we have a stored discord_channel_id, try to grant the user access to that channel
            try:
                channel_id = group.get('discord_channel_id') if isinstance(group, dict) else group[2]
            except Exception:
                channel_id = None
            channel = None
            if channel_id:
                try:
                    channel = ctx.guild.get_channel(int(channel_id))
                except Exception:
                    channel = None
            if channel:
                try:
                    enqueue_permission(ctx.guild.id, channel.id, ctx.author.id, view=True, send=True)
                except Exception as e:
                    logging.error(f"Failed to set channel permissions for user {ctx.author} on {channel.name}: {e}")
                    await ctx.send("Registration partially succeeded but failed to add you to some groups. Please try again in 10 minutes or contact the instructor.")
                    return

        # Remove access to the register channel so the user no longer sees it
        register_channel = discord.utils.get(ctx.guild.text_channels, name="📝register")
        if register_channel:
                    try:
                        # The server owner cannot be denied access; skip removing owner
                        if ctx.author == ctx.guild.owner:
                            logging.debug("Attempted to remove server owner from register channel; skipping.")
                        else:
                            # Enqueue deny viewing and sending in the register channel for this member
                            enqueue_permission(ctx.guild.id, register_channel.id, ctx.author.id, view=False, send=False)
                    except Exception as e:
                        logging.debug(f"Failed to enqueue register channel permission change for {ctx.author}: {e}")
        await ctx.send("You have been registered and added to your group channels.")

@bot.command()
@commands.is_owner()
async def format(ctx, class_id: int):
    # Only allow the server owner to run this command (commands.is_owner enforces this)
    # We'll create the channels and then record the discord server id for the class

    # Delete all channels
    for channel in ctx.guild.channels:
        try:
            await channel.delete()
        except Exception as e:
            logging.warning(f"Failed to delete channel {channel.name}: {e}")

    # Create new channels
    # Set permissions for announcements channel
    announcement = await ctx.guild.create_text_channel("📢announcements", overwrites=get_announcement_overwrites(ctx))
    register = await ctx.guild.create_text_channel("📝register", overwrites=get_register_overwrites(ctx))

    # Create category
    category = await ctx.guild.create_category("📁 Group Channels") #

    # Send and pin welcome message in announcements channel
    welcome_message = (
        f""":loudspeaker: **WELCOME to the {ctx.guild.name} discord server!** :wave:
\n────────────────────────────────\n
:warning: **IMPORTANT**  
This server is designed to **facilitate collaboration and learning** among students.  
Here you will **communicate and work with your team** to complete class projects.  
\n────────────────────────────────\n
:pushpin: **Key Channels**  
\n- :loudspeaker: **#announcements** - Official updates and important class info.
- :pencil: **#register** - Please go here *right away* and run:  `/register student_id` This will place you in your private group channels.  
\n────────────────────────────────\n
:tada: **Happy Learning & Collaboration!** :tada:"""
    )
    msg = await announcement.send(welcome_message)
    await msg.pin()

    # Create a private 'Commands' channel visible only to the server owner and the bot
    overwrites = {
        ctx.guild.default_role: discord.PermissionOverwrite(view_channel=False),
        ctx.guild.owner: discord.PermissionOverwrite(view_channel=True, send_messages=True),
        ctx.me: discord.PermissionOverwrite(view_channel=True, send_messages=True)
    }
    commands_channel = await ctx.guild.create_text_channel("🤖Commands", overwrites=overwrites)

    commands_help = (
        "🤖 Bot Commands:\n"
        "- `/createGroups <assignment_id>` - (owner) Create private group channels and assign registered members.\n"
        "- `/register <student_id>` - Register yourself and be added to your group channels.\n"
        "- `/format <class_id>` - (owner) Recreate server channels for this class (this command).\n"
        "More commands may be added in the future. If something fails, contact the instructor."
    )
    await commands_channel.send(commands_help)

    # Update the classes table with the guild/server id
    try:
        ok = await init_db_pool()
        if ok:
            async with db_pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("UPDATE classes SET discord_server_id = %s WHERE id = %s", (str(ctx.guild.id), class_id))
                    await conn.commit()
    except Exception as e:
        logging.error(f"Failed to update classes.discord_server_id for class {class_id}: {e}")



bot.run(TOKEN, log_handler=handler, log_level=logging.DEBUG)