<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class SetSystemAdministrator extends Command
{
    protected $signature = 'apotek:system-admin {email} {--revoke} {--create : Buat akun baru tanpa password default}';

    protected $description = 'Tetapkan/cabut Admin Sistem pada akun yang sudah terdaftar (hanya operator server).';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        $created = false;
        if (! $user && $this->option('create') && ! $this->option('revoke')) {
            $email = mb_strtolower(trim($this->argument('email')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Email tidak valid.');

                return self::FAILURE;
            }
            $user = User::create(['name' => 'Admin Sistem Kotzen', 'email' => $email, 'password' => bin2hex(random_bytes(32))]);
            $created = true;
        }
        if (! $user) {
            $this->error('Akun tidak ditemukan. Daftarkan akun terlebih dahulu.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user) {
            $user->forceFill(['is_system_admin' => ! $this->option('revoke')])->save();
            DB::table('system_admin_events')->insert(['user_id' => $user->id, 'granted' => ! $this->option('revoke'), 'created_at' => now()]);
        });
        $this->info($this->option('revoke') ? 'Hak Admin Sistem dicabut.' : 'Hak Admin Sistem diberikan.');
        if ($created) {
            $token = Password::createToken($user);
            $this->line('Tentukan password melalui: '.route('password.reset', ['token' => $token, 'email' => $user->email]));
        }

        return self::SUCCESS;
    }
}
