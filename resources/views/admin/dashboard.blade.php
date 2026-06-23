<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgencyMind Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="h-full overflow-hidden flex bg-[#0f172a] text-white">
    <!-- Sidebar -->
    <aside class="w-64 bg-[#1e293b] border-r border-slate-700/50 hidden md:flex flex-col shadow-2xl">
        <div class="h-16 flex items-center px-6 border-b border-slate-700/50">
            <span class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400">AgencyMind Admin</span>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2">
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 bg-blue-500/10 text-blue-400 rounded-lg font-medium transition-colors border border-blue-500/20">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                Dashboard
            </a>
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 text-slate-400 hover:text-slate-200 hover:bg-slate-800 rounded-lg font-medium transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                Clients
            </a>
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 text-slate-400 hover:text-slate-200 hover:bg-slate-800 rounded-lg font-medium transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Invoices
            </a>
            <a href="/pulse" target="_blank" class="flex items-center gap-3 px-3 py-2.5 text-slate-400 hover:text-slate-200 hover:bg-slate-800 rounded-lg font-medium transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                Pulse Monitor
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden relative">
        <!-- Header -->
        <header class="h-16 flex items-center justify-between px-8 bg-[#1e293b]/80 backdrop-blur-md border-b border-slate-700/50 sticky top-0 z-10">
            <h1 class="text-xl font-semibold text-slate-100">Overview</h1>
            <div class="flex items-center gap-4">
                <div class="h-9 w-9 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 p-0.5 shadow-lg shadow-indigo-500/20">
                    <div class="h-full w-full rounded-full bg-[#1e293b] flex items-center justify-center text-sm font-medium">AD</div>
                </div>
            </div>
        </header>

        <!-- Content scrollable -->
        <div class="flex-1 overflow-auto p-8 relative">
            
            <!-- Warning Banner -->
            <div class="mb-8 rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-4">
                <svg class="w-6 h-6 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div>
                    <h3 class="text-amber-500 font-medium">Custom Dashboard Prototype</h3>
                    <p class="text-amber-500/80 text-sm mt-1">
                        Paket Filament v3 resmi belum mendung Laravel 12 secara utuh. Ini adalah dashboard kustom sebagai *mockup* sementara untuk mengelola _Billing_ dan _Clients_ Anda sembari menunggu rilis Filament.
                    </p>
                </div>
            </div>

            <!-- Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Card 1 -->
                <div class="bg-[#1e293b] border border-slate-700/50 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-500/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex items-center justify-between mb-4 relative">
                        <h3 class="text-slate-400 font-medium text-sm">Total Revenue (Est)</h3>
                        <div class="p-2 bg-blue-500/10 rounded-lg text-blue-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-slate-100 relative">$142.50</p>
                    <p class="text-emerald-400 text-sm mt-2 font-medium flex items-center gap-1 relative">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                        +12.5% dari bulan lalu
                    </p>
                </div>

                <!-- Card 2 -->
                <div class="bg-[#1e293b] border border-slate-700/50 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex items-center justify-between mb-4 relative">
                        <h3 class="text-slate-400 font-medium text-sm">Active Clients</h3>
                        <div class="p-2 bg-indigo-500/10 rounded-lg text-indigo-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-slate-100 relative">
                        {{ \App\Models\Client::count() ?? 1 }}
                    </p>
                    <p class="text-slate-400 text-sm mt-2 font-medium flex items-center gap-1 relative">
                        Tenant aktif saat ini
                    </p>
                </div>

                <!-- Card 3 -->
                <div class="bg-[#1e293b] border border-slate-700/50 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-500/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex items-center justify-between mb-4 relative">
                        <h3 class="text-slate-400 font-medium text-sm">Draft Invoices</h3>
                        <div class="p-2 bg-purple-500/10 rounded-lg text-purple-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-slate-100 relative">
                        {{ \App\Models\Invoice::count() ?? 0 }}
                    </p>
                    <p class="text-slate-400 text-sm mt-2 font-medium flex items-center gap-1 relative">
                        Menunggu review
                    </p>
                </div>
            </div>

            <!-- Table Section -->
            <div class="bg-[#1e293b] border border-slate-700/50 rounded-2xl shadow-xl overflow-hidden">
                <div class="p-6 border-b border-slate-700/50 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-slate-100">Registered Clients</h2>
                    <button class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium transition-colors shadow-lg shadow-blue-500/20">
                        + New Client
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-400">
                        <thead class="bg-slate-800/50 text-xs uppercase font-semibold text-slate-300">
                            <tr>
                                <th class="px-6 py-4">Client Name</th>
                                <th class="px-6 py-4">Schema</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Created At</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700/50">
                            @foreach(\App\Models\Client::all() as $client)
                            <tr class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-6 py-4 font-medium text-slate-200">{{ $client->name }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-blue-400">{{ $client->schema_name }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        {{ ucfirst($client->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">{{ $client->created_at->format('M d, Y') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="text-indigo-400 hover:text-indigo-300 font-medium">Edit</button>
                                </td>
                            </tr>
                            @endforeach
                            @if(\App\Models\Client::count() == 0)
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-slate-500">No clients registered yet.</td>
                            </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</body>
</html>
