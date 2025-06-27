const express = require('express');
const dotenv = require('dotenv');
const cors = require('cors');
const mysql = require('mysql2/promise');
const { GoogleGenerativeAI } = require('@google/generative-ai');

dotenv.config();

const app = express();
const port = 4000;

app.use(express.json());
app.use(cors());

const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

// Database connection helper
async function connectToDB() {
  return mysql.createConnection({
    host: 'db',
    user: process.env.MYSQL_USER,
    password: process.env.MYSQL_PASSWORD,
    database: process.env.MYSQL_DATABASE
  });
}

// Helper to fetch all group-related data
async function getGroupData(groupId) {
  const conn = await connectToDB();

  const [[groupInfo]] = await conn.execute(`
    SELECT g.name AS group_name, a.title AS assignment_title, g.assignment_id
    FROM \`groups\` g
    JOIN assignments a ON g.assignment_id = a.id
    WHERE g.id = ?
  `, [groupId]);

  const [members] = await conn.execute(`
    SELECT s.first_name, s.last_name, gm.role, s.discord_user_id, s.id as student_id
    FROM group_members gm
    JOIN students s ON gm.student_id = s.id
    WHERE gm.group_id = ?
  `, [groupId]);

  const [messages] = await conn.execute(`
    SELECT author_id, content, timestamp
    FROM discord_messages
    WHERE group_id = ?
  `, [groupId]);

  const [files] = await conn.execute(`
    SELECT user_email, activity_type, file_name, timestamp
    FROM sharepoint_activities
    WHERE group_id = ?
  `, [groupId]);

  await conn.end();

  return { groupInfo, members, messages, files };
}

// Gemini prompt builder
function buildPrompt(data) {
  const { groupInfo, members, messages, files } = data;

  return {
    contents: [{
      parts: [{
        text: `
You are an AI assistant evaluating university student group work.

Assignment: ${groupInfo.assignment_title}
Group: ${groupInfo.group_name}

Group Members:
${JSON.stringify(members, null, 2)}

Discord Messages (author_id, timestamp, content):
${JSON.stringify(messages, null, 2)}

SharePoint Activities (user_email, activity_type, file_name, timestamp):
${JSON.stringify(files, null, 2)}

Return a summary JSON report using this format:
{
  group_name: string,
  assignment_title: string,
  timeframe: { start: ISO string, end: ISO string },
  members: [{ name, role, discord_messages, file_activities, participation_level, notes }],
  collaboration_analysis: { dominant_participants, quiet_members, communication_pattern, overall_quality },
  file_activity_analysis: { total_files_edited, rush_period, consistent_contributors, notes },
  ai_insights: [string],
  group_dynamics_score: integer
}
        `
      }]
    }]
  };
}

// Main endpoint to generate reports for all groups in an assignment
app.get('/search', async (req, res) => {
  const assignmentId = req.query.assignment_id;

  if (!assignmentId) {
    return res.status(400).json({ error: 'Missing assignment_id' });
  }

  try {
    const conn = await connectToDB();
    const [groups] = await conn.execute(
      "SELECT id FROM `groups` WHERE assignment_id = " + assignmentId
    );
    await conn.end();

    const genAI = new GoogleGenerativeAI(process.env.GEMINI_API_KEY);
    const model = genAI.getGenerativeModel({ model: 'gemini-1.5-flash' });

    const reports = [];

    for (const group of groups) {
      try {
        const groupData = await getGroupData(group.id);
        const prompt = buildPrompt(groupData);

        const result = await model.generateContent(prompt);
        const text = result.response.text();
        const match = text.match(/\{\s*"group_name"[\s\S]*?\}/);

        if (match) {
          const parsed = JSON.parse(match[0]);
          reports.push(parsed);
        } else {
          console.warn(`Invalid format for group ${group.id}`);
        }
      } catch (groupErr) {
        console.error(`Failed processing group ${group.id}:`, groupErr.message);
      }
    }

    res.json({ reports });

  } catch (error) {
    console.error('General error:', error);
    res.status(500).json({ error: 'Failed to generate reports for assignment' });
  }
});

app.listen(port, () => {
  console.log(`API listening on http://localhost:${port}`);
});
