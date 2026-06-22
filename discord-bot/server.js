const express = require('express');
const { client } = require('./bot');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../.env') }); // Load main Laravel .env

const app = express();
const port = process.env.DISCORD_BOT_PORT || 3000;
const serviceToken = process.env.DISCORD_BOT_API_TOKEN;

app.use(express.json());

// Authentication Middleware
const authMiddleware = (req, res, next) => {
    const authHeader = req.headers.authorization;
    if (!authHeader || !authHeader.startsWith('Bearer ')) {
        return res.status(401).json({ message: 'Unauthorized webhook access' });
    }
    const token = authHeader.split(' ')[1];
    if (token !== serviceToken) {
        return res.status(401).json({ message: 'Invalid webhook token' });
    }
    next();
};

// Webhook Receiver Endpoint
app.post('/api/v1/webhooks/job-result', authMiddleware, async (req, res) => {
    const { job_id, type, discord_channel_id, discord_user_id, client_slug, result, error } = req.body;

    console.log(`Received job callback. ID: ${job_id}, Type: ${type}, Channel: ${discord_channel_id}`);

    if (!discord_channel_id) {
        return res.status(400).json({ message: 'Missing discord_channel_id' });
    }

    try {
        const channel = await client.channels.fetch(discord_channel_id);
        if (!channel) {
            console.error(`Channel ${discord_channel_id} not found.`);
            return res.status(404).json({ message: 'Discord channel not found' });
        }

        let embed;

        if (error) {
            // Handle error output
            embed = {
                title: `❌ Pekerjaan Gagal (Job ID: ${job_id})`,
                description: `Terjadi kesalahan saat mengeksekusi perintah untuk klien **${client_slug}**:\n\n\`\`\`\n${error}\n\`\`\``,
                color: 0xff0000,
                timestamp: new Date().toISOString()
            };
        } else if (type === 'log_check') {
            // Handle log check output
            embed = {
                title: `📋 Ringkasan Analisis Log: ${client_slug.toUpperCase()}`,
                description: result.summary,
                color: 0x3498db, // Blue
                timestamp: new Date().toISOString(),
                footer: { text: `Job ID: ${job_id}` }
            };
        } else if (type === 'validate') {
            // Handle validation report
            const statusColors = {
                pass: 0x2ecc71,        // Green
                pass_with_notes: 0xf1c40f, // Yellow
                fail: 0xe74c3c         // Red
            };

            const color = statusColors[result.overall_status] || 0x7f8c8d;

            const fields = [];
            // Parse categories
            if (result.categories) {
                for (const [catName, checks] of Object.entries(result.categories)) {
                    let checksStr = '';
                    checks.forEach(c => {
                        const icon = c.status === 'pass' ? '✅' : (c.status === 'warning' ? '⚠️' : '❌');
                        checksStr += `${icon} **${c.name}** (${c.severity})\n`;
                        if (c.status !== 'pass') {
                            checksStr += `   *${c.message}*\n`;
                        }
                    });
                    fields.push({
                        name: `📁 ${catName.toUpperCase()}`,
                        value: checksStr || 'Tidak ada pemeriksaan',
                        inline: false
                    });
                }
            }

            embed = {
                title: `🔍 Laporan Validasi Proyek: ${client_slug.toUpperCase()}`,
                description: `**Status Akhir: ${result.overall_status.toUpperCase()}**\n\n**Analisis Kualitatif AI:**\n${result.ai_qualitative_assessment}\n\n**Catatan Arsitektur:**\n${result.architectural_notes}`,
                color: color,
                fields: fields,
                timestamp: new Date().toISOString(),
                footer: { text: `Job ID: ${job_id}` }
            };
        }

        // Send message with user mention ping
        await channel.send({
            content: `<@${discord_user_id}>`,
            embeds: [embed]
        });

        return res.status(200).json({ message: 'Notification sent successfully' });

    } catch (err) {
        console.error('Failed to send Discord notification:', err);
        return res.status(500).json({ message: 'Internal server error', error: err.message });
    }
});

app.listen(port, () => {
    console.log(`Webhook server listening on port ${port}`);
});
