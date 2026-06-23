const { Client, GatewayIntentBits, REST, Routes, SlashCommandBuilder } = require('discord.js');
const axios = require('axios');
const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '../.env') }); // Load main Laravel .env

const token = process.env.DISCORD_BOT_TOKEN;
const clientId = process.env.DISCORD_CLIENT_ID;
const apiUrl = process.env.AGENCYMIND_API_URL || 'http://localhost:8000/api';
const serviceToken = process.env.DISCORD_BOT_API_TOKEN;

// Initialize Discord Client
const client = new Client({ intents: [GatewayIntentBits.Guilds] });

// Define Slash Commands
const commands = [
    new SlashCommandBuilder()
        .setName('log-check')
        .setDescription('Cek ringkasan anomali log proyek klien tertentu')
        .addStringOption(option =>
            option.setName('client')
                .setDescription('Slug klien (e.g. toko-abc)')
                .setRequired(true))
        .addStringOption(option =>
            option.setName('range')
                .setDescription('Rentang waktu (e.g. 24h, 7d, 1h)')
                .setRequired(false)),

    new SlashCommandBuilder()
        .setName('validate')
        .setDescription('Memicu validasi proyek otonom')
        .addStringOption(option =>
            option.setName('project')
                .setDescription('Slug proyek/klien (e.g. toko-abc)')
                .setRequired(true))
        .addStringOption(option =>
            option.setName('path')
                .setDescription('Path direktori absolut untuk divalidasi (opsional)')
                .setRequired(false)),

    new SlashCommandBuilder()
        .setName('status')
        .setDescription('Menampilkan status kesehatan terkini proyek klien')
        .addStringOption(option =>
            option.setName('client')
                .setDescription('Slug klien (e.g. toko-abc)')
                .setRequired(true)),

    new SlashCommandBuilder()
        .setName('threshold')
        .setDescription('Mengubah ambang batas alert per klien')
        .addStringOption(option =>
            option.setName('client')
                .setDescription('Slug klien (e.g. toko-abc)')
                .setRequired(true))
        .addStringOption(option =>
            option.setName('parameter')
                .setDescription('Parameter metrik (e.g. error_spike, warning_count)')
                .setRequired(true))
        .addIntegerOption(option =>
            option.setName('value')
                .setDescription('Nilai ambang batas baru')
                .setRequired(true)),

    new SlashCommandBuilder()
        .setName('report-weekly')
        .setDescription('Memicu pembuatan draf laporan mingguan untuk klien')
        .addStringOption(option =>
            option.setName('client')
                .setDescription('Slug klien (e.g. toko-abc)')
                .setRequired(true))
        .addIntegerOption(option =>
            option.setName('days')
                .setDescription('Rentang hari (default 7)')
                .setRequired(false)),

    new SlashCommandBuilder()
        .setName('report-approve')
        .setDescription('Menyetujui dan mengirim draf laporan mingguan')
        .addStringOption(option =>
            option.setName('client')
                .setDescription('Slug klien (e.g. toko-abc)')
                .setRequired(true))
        .addIntegerOption(option =>
            option.setName('report_id')
                .setDescription('ID Laporan Mingguan')
                .setRequired(true))
].map(command => command.toJSON());

// Register Slash Commands
async function registerCommands() {
    if (!token || !clientId) {
        console.warn('Discord token or client ID is not configured. Skipping command registration.');
        return;
    }
    const rest = new REST({ version: '10' }).setToken(token);
    try {
        console.log('Started refreshing application (/) commands.');
        await rest.put(
            Routes.applicationCommands(clientId),
            { body: commands }
        );
        console.log('Successfully reloaded application (/) commands.');
    } catch (error) {
        console.error('Failed to register slash commands:', error);
    }
}

// Handle Slash Command Interactions
client.on('interactionCreate', async interaction => {
    if (!interaction.isChatInputCommand()) return;

    const { commandName, options, channelId, user } = interaction;
    const bearerHeader = { headers: { Authorization: `Bearer ${serviceToken}` } };

    try {
        if (commandName === 'log-check') {
            await interaction.reply({ content: '⏳ Sedang memproses analisis log, mohon tunggu...', ephemeral: false });
            const clientSlug = options.getString('client');
            const range = options.getString('range') || '24h';

            const response = await axios.post(`${apiUrl}/v1/commands/log-check`, {
                client_slug: clientSlug,
                range: range,
                discord_channel_id: channelId,
                requested_by_discord_id: user.id
            }, bearerHeader);

            await interaction.followUp({ content: `✅ Permintaan log-check diterima (Job ID: ${response.data.job_id}). Hasil akan dikirim ke channel ini sebentar lagi.`, ephemeral: true });

        } else if (commandName === 'validate') {
            await interaction.reply({ content: '⏳ Menjalankan validasi proyek otonom, mohon tunggu...', ephemeral: false });
            const projectSlug = options.getString('project');
            const projectPath = options.getString('path') || null;

            const response = await axios.post(`${apiUrl}/v1/commands/validate`, {
                project_slug: projectSlug,
                project_path: projectPath,
                discord_channel_id: channelId,
                requested_by_discord_id: user.id
            }, bearerHeader);

            await interaction.followUp({ content: `✅ Permintaan validasi diterima (Job ID: ${response.data.job_id}). Laporan validasi akan dikirim setelah selesai.`, ephemeral: true });

        } else if (commandName === 'status') {
            await interaction.reply({ content: '⏳ Mengambil status proyek...', ephemeral: false });
            const clientSlug = options.getString('client');

            const response = await axios.get(`${apiUrl}/v1/clients/${clientSlug}/status-detailed`, bearerHeader);
            const data = response.data;

            let healthIcon = '🟢';
            if (data.health === 'warning') healthIcon = '🟡';
            if (data.health === 'critical') healthIcon = '🔴';

            const embed = {
                title: `Status Kesehatan Proyek: ${data.client.toUpperCase()}`,
                color: data.health === 'critical' ? 0xff0000 : (data.health === 'warning' ? 0xffcc00 : 0x00ff00),
                fields: [
                    { name: 'Kesehatan', value: `${healthIcon} ${data.health.toUpperCase()}`, inline: true },
                    { name: 'Insiden Terbuka', value: `${data.open_incidents} kejadian`, inline: true },
                    { name: 'Validasi Terakhir', value: data.last_validation ? `${data.last_validation.overall_status.toUpperCase()} (${data.last_validation.created_at})` : 'Belum pernah divalidasi', inline: false }
                ],
                timestamp: new Date().toISOString()
            };

            await interaction.editReply({ content: 'Berikut adalah status terkini:', embeds: [embed] });

        } else if (commandName === 'threshold') {
            await interaction.reply({ content: '⏳ Mengupdate ambang batas...', ephemeral: false });
            const clientSlug = options.getString('client');
            const parameter = options.getString('parameter');
            const value = options.getInteger('value');

            await axios.post(`${apiUrl}/v1/commands/threshold-set`, {
                client_slug: clientSlug,
                parameter: parameter,
                value: value,
                requested_by_discord_id: user.id
            }, bearerHeader);

            await interaction.editReply({ content: `✅ Ambang batas untuk **${clientSlug}** (parameter: **${parameter}**) berhasil diubah menjadi **${value}**.` });

        } else if (commandName === 'report-weekly') {
            await interaction.reply({ content: '⏳ Memicu Reporting Agent untuk membuat draf laporan mingguan...', ephemeral: false });
            const clientSlug = options.getString('client');
            const days = options.getInteger('days') || 7;

            const response = await axios.post(`${apiUrl}/v1/commands/report-weekly`, {
                client_slug: clientSlug,
                days: days,
                discord_channel_id: channelId,
                requested_by_discord_id: user.id
            }, bearerHeader);

            await interaction.followUp({ content: `✅ Permintaan laporan diterima (Job ID: ${response.data.job_id}). Draf laporan akan dikirim setelah agen selesai merangkum.`, ephemeral: true });

        } else if (commandName === 'report-approve') {
            await interaction.reply({ content: '⏳ Menyetujui dan mengirim laporan...', ephemeral: false });
            const clientSlug = options.getString('client');
            const reportId = options.getInteger('report_id');

            const response = await axios.post(`${apiUrl}/v1/commands/report-approve`, {
                client_slug: clientSlug,
                report_id: reportId,
                requested_by_discord_id: user.id
            }, bearerHeader);

            await interaction.editReply({ content: `✅ Laporan Mingguan ID **${reportId}** berhasil disetujui dan dikirim.` });
        }
    } catch (error) {
        console.error('Error executing slash command:', error.response?.data || error.message);
        const errorMsg = error.response?.data?.message || error.message;
        await interaction.followUp({ content: `❌ Terjadi kesalahan saat memproses perintah: ${errorMsg}`, ephemeral: true });
    }
});

// Boot Discord Bot
if (token) {
    client.login(token).then(() => {
        console.log(`Logged in as ${client.user.tag}!`);
        registerCommands();
    }).catch(err => {
        console.error('Discord login failed:', err);
    });
} else {
    console.warn('DISCORD_BOT_TOKEN is not set. Discord bot listener is disabled.');
}

module.exports = { client }; // Export client instance for server.js
