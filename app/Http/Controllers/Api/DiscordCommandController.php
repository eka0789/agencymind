<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\ValidationProfile;
use App\Models\Tenant\Incident;
use App\Models\Tenant\ValidationResult;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDiscordLogCheck;
use App\Jobs\ProcessDiscordProjectValidation;
use App\Jobs\GenerateWeeklyReport;
use App\Models\Tenant\WeeklyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class DiscordCommandController extends Controller
{
    /**
     * POST /api/v1/commands/log-check
     */
    public function logCheck(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_slug' => 'required|string|exists:clients,slug',
            'range' => 'required|string',
            'discord_channel_id' => 'required|string',
            'requested_by_discord_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('client_slug'))->firstOrFail();
        $discordUserId = $request->input('requested_by_discord_id');

        // Retrieve global user matching discord_user_id or fallback to first owner
        $user = User::where('discord_user_id', $discordUserId)->first()
            ?: User::where('role', 'owner')->first()
            ?: User::first();

        if (!$user) {
            return response()->json(['message' => 'No active user found to perform this action.'], 500);
        }

        $jobId = (string) Str::uuid();

        // Write append-only central audit log
        AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'log_check',
            'command_text' => "/log-check client:{$client->slug} range:{$request->input('range')}",
            'payload_json' => $request->all(),
            'result_summary' => "Job queued with ID: {$jobId}",
        ]);

        // Dispatch background job to critical/default queue
        ProcessDiscordLogCheck::dispatch(
            $client->id,
            $request->input('range'),
            $jobId,
            $request->input('discord_channel_id'),
            $discordUserId
        )->onQueue('critical');

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued'
        ], 202);
    }

    /**
     * POST /api/v1/commands/validate
     */
    public function validateProject(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_slug' => 'required|string|exists:clients,slug',
            'discord_channel_id' => 'required|string',
            'requested_by_discord_id' => 'required|string',
            'project_path' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('project_slug'))->firstOrFail();
        $discordUserId = $request->input('requested_by_discord_id');
        $projectPath = $request->input('project_path') ?: base_path(); // Default to workspace base path

        $user = User::where('discord_user_id', $discordUserId)->first()
            ?: User::where('role', 'owner')->first()
            ?: User::first();

        if (!$user) {
            return response()->json(['message' => 'No active user found to perform this action.'], 500);
        }

        $jobId = (string) Str::uuid();

        AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'validate',
            'command_text' => "/validate project:{$client->slug}",
            'payload_json' => $request->all(),
            'result_summary' => "Validation Job queued with ID: {$jobId}",
        ]);

        ProcessDiscordProjectValidation::dispatch(
            $client->id,
            $projectPath,
            $user->id,
            $jobId,
            $request->input('discord_channel_id'),
            $discordUserId
        )->onQueue('critical');

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued'
        ], 202);
    }

    /**
     * POST /api/v1/commands/threshold-set
     */
    public function thresholdSet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_slug' => 'required|string|exists:clients,slug',
            'parameter' => 'required|string',
            'value' => 'required|integer|min:1',
            'requested_by_discord_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('client_slug'))->firstOrFail();
        $discordUserId = $request->input('requested_by_discord_id');
        $parameter = $request->input('parameter');
        $value = $request->input('value');

        $user = User::where('discord_user_id', $discordUserId)->first()
            ?: User::where('role', 'owner')->first()
            ?: User::first();

        if (!$user) {
            return response()->json(['message' => 'No active user found to perform this action.'], 500);
        }

        // Update validation profile threshold
        $profile = ValidationProfile::where('client_id', $client->id)->first();
        if (!$profile) {
            $profile = ValidationProfile::create([
                'client_id' => $client->id,
                'name' => $client->name . ' Custom Profile',
                'rules_json' => [
                    'thresholds' => []
                ]
            ]);
        }

        $rules = $profile->rules_json;
        if (!isset($rules['thresholds'])) {
            $rules['thresholds'] = [];
        }

        $updated = false;
        foreach ($rules['thresholds'] as &$threshold) {
            if (($threshold['name'] ?? '') === $parameter || ($threshold['metric'] ?? '') === $parameter) {
                $threshold['limit'] = (int) $value;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $rules['thresholds'][] = [
                'name' => $parameter,
                'severity' => 'critical',
                'metric' => str_contains($parameter, 'error') ? 'error_count' : 'warning_count',
                'window_minutes' => 5,
                'limit' => (int) $value,
            ];
        }

        $profile->rules_json = $rules;
        $profile->save();

        AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'threshold_set',
            'command_text' => "/threshold set client:{$client->slug} parameter:{$parameter} value:{$value}",
            'payload_json' => $request->all(),
            'result_summary' => "Threshold updated successfully.",
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Threshold updated successfully'
        ], 200);
    }

    /**
     * GET /api/v1/clients/{client}/status-detailed
     * (Accessible by Discord bot, uses tenant.schema middleware to switch dynamically)
     */
    public function clientStatus(Request $request)
    {
        $client = $request->attributes->get('client');
        if (!$client) {
            return response()->json(['message' => 'Client not found.'], 404);
        }
        $openIncidents = Incident::where('status', 'open')->get();
        $openIncidentsCount = $openIncidents->count();

        // Determine client health status
        $health = 'healthy';
        if ($openIncidentsCount > 0) {
            $hasCritical = $openIncidents->contains(function ($incident) {
                return $incident->severity === 'critical';
            });
            $health = $hasCritical ? 'critical' : 'warning';
        }

        $lastValidation = ValidationResult::orderBy('created_at', 'desc')->first();

        return response()->json([
            'client' => $client->slug,
            'health' => $health,
            'open_incidents' => $openIncidentsCount,
            'last_validation' => $lastValidation ? [
                'overall_status' => $lastValidation->overall_status,
                'created_at' => $lastValidation->created_at ? $lastValidation->created_at->toIso8601String() : null,
            ] : null,
        ]);
    }

    /**
     * POST /api/v1/commands/report-weekly
     */
    public function reportWeekly(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_slug' => 'required|string|exists:clients,slug',
            'discord_channel_id' => 'required|string',
            'requested_by_discord_id' => 'required|string',
            'days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('client_slug'))->firstOrFail();
        $discordUserId = $request->input('requested_by_discord_id');
        $days = $request->input('days', 7);

        $user = User::where('discord_user_id', $discordUserId)->first()
            ?: User::where('role', 'owner')->first()
            ?: User::first();

        if (!$user) {
            return response()->json(['message' => 'No active user found to perform this action.'], 500);
        }

        $jobId = (string) Str::uuid();

        AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'report_weekly',
            'command_text' => "/report-weekly client:{$client->slug} days:{$days}",
            'payload_json' => $request->all(),
            'result_summary' => "Weekly Report Job queued with ID: {$jobId}",
        ]);

        GenerateWeeklyReport::dispatch(
            $client->id,
            $jobId,
            $request->input('discord_channel_id'),
            $discordUserId,
            $days
        )->onQueue('default');

        return response()->json([
            'job_id' => $jobId,
            'status' => 'queued'
        ], 202);
    }

    /**
     * POST /api/v1/commands/report-approve
     */
    public function reportApprove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_slug' => 'required|string|exists:clients,slug',
            'report_id' => 'required|integer',
            'requested_by_discord_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('client_slug'))->firstOrFail();
        $discordUserId = $request->input('requested_by_discord_id');
        $reportId = $request->input('report_id');

        $user = User::where('discord_user_id', $discordUserId)->first()
            ?: User::where('role', 'owner')->first()
            ?: User::first();

        if (!$user) {
            return response()->json(['message' => 'No active user found to perform this action.'], 500);
        }

        // Connect to tenant schema temporarily
        $tenantManager = app(\App\Domain\Tenancy\TenantManager::class);
        $tenantManager->switchToTenant($client);

        $report = WeeklyReport::find($reportId);
        if (!$report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        if ($report->status === 'sent') {
            return response()->json(['message' => 'Report is already sent.'], 400);
        }

        $report->status = 'sent';
        $report->reviewed_by_user_id = $user->id;
        $report->sent_at = now();
        $report->save();

        AuditLog::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'report_approve',
            'command_text' => "/report-approve client:{$client->slug} report_id:{$reportId}",
            'payload_json' => $request->all(),
            'result_summary' => "Weekly Report ID {$reportId} approved and sent.",
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Report ID {$reportId} has been approved and sent successfully."
        ], 200);
    }
}
