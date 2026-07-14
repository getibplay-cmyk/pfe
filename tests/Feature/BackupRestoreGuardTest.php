<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupRestoreGuardTest extends TestCase
{
    public function test_backup_outputs_are_ignored_and_scripts_never_read_env_passwords(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));
        $scripts = collect(['backup.ps1', 'restore.ps1', 'verify-restore.ps1'])
            ->map(fn (string $file) => file_get_contents(base_path('scripts/'.$file)))
            ->implode("\n");

        $this->assertStringContainsString('/backups', $gitignore);
        $this->assertStringContainsString('--format=custom', $scripts);
        $this->assertStringContainsString('SHA256', $scripts);
        $this->assertStringContainsString('PGPASSFILE', file_get_contents(base_path('docs/operations/backup-and-restore.md')));
        $this->assertStringNotContainsString('DB_PASSWORD', $scripts);
        $this->assertStringNotContainsString('PGPASSWORD', $scripts);
        $this->assertStringNotContainsString('Get-Content .env', $scripts);
    }

    public function test_restore_script_refuses_the_development_database_before_any_restore(): void
    {
        $result = Process::timeout(15)->run([
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', base_path('scripts/restore.ps1'),
            '-BackupDirectory', 'missing-backup',
            '-DatabaseName', 'rentfleet',
            '-ConfirmRestore',
        ]);

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('rentfleet_restore_test', $result->errorOutput().$result->output());
        $this->assertStringNotContainsString('pg_restore a échoué', $result->errorOutput().$result->output());
    }
}
