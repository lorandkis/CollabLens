import discord

def get_announcement_overwrites(ctx):
    return {
        ctx.guild.default_role: discord.PermissionOverwrite(
            view_channel=True,
            read_message_history=True,
            add_reactions=True,
            send_messages=False,
            send_tts_messages=False,
            manage_messages=False,
            embed_links=False,
            attach_files=False,
            mention_everyone=False,
            external_emojis=False,
            use_external_stickers=False,
            manage_channels=False,
            manage_permissions=False,
            manage_webhooks=False,
            create_public_threads=False,
            create_private_threads=False,
            send_messages_in_threads=True,
            use_application_commands=False,
            manage_threads=False,
            send_voice_messages=False,
            priority_speaker=False,
            stream=False,
            speak=False,
            mute_members=False,
            deafen_members=False,
            move_members=False,
            request_to_speak=False,
            connect=False,
            use_voice_activation=False,
            use_soundboard=False,
            use_embedded_activities=False
        ),
        ctx.guild.owner: discord.PermissionOverwrite(
            send_messages=True,
            add_reactions=True,
            send_messages_in_threads=True,
            create_public_threads=True,
            create_private_threads=True,
            read_message_history=True,
            view_channel=True,
            manage_channels=True,
            manage_permissions=True
        ),
        ctx.me: discord.PermissionOverwrite(
            send_messages=True,
            add_reactions=True,
            send_messages_in_threads=True,
            create_public_threads=True,
            create_private_threads=True,
            read_message_history=True,
            view_channel=True,
            manage_channels=True,
            manage_permissions=True
        )
    }

def get_register_overwrites(ctx):
    return {
        ctx.guild.default_role: discord.PermissionOverwrite(
            view_channel=True,
            read_message_history=False,
            send_messages=True,
            add_reactions=False,
            send_tts_messages=False,
            manage_messages=False,
            embed_links=False,
            attach_files=False,
            mention_everyone=False,
            external_emojis=False,
            use_external_stickers=False,
            manage_channels=False,
            manage_permissions=False,
            manage_webhooks=False,
            create_public_threads=False,
            create_private_threads=False,
            send_messages_in_threads=False,
            use_application_commands=True,
            manage_threads=False,
            send_voice_messages=False,
            priority_speaker=False,
            stream=False,
            speak=False,
            mute_members=False,
            deafen_members=False,
            move_members=False,
            request_to_speak=False,
            connect=False,
            use_voice_activation=False,
            use_soundboard=False,
            use_embedded_activities=False
        ),
        ctx.guild.owner: discord.PermissionOverwrite(
            send_messages=True,
            view_channel=True,
            read_message_history=True,
            manage_channels=True,
            manage_permissions=True
        ),
        ctx.me: discord.PermissionOverwrite(
            send_messages=True,
            view_channel=True,
            read_message_history=True,
            manage_channels=True,
            manage_permissions=True
        )
    }
