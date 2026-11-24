<?php

namespace App\Http\Controllers;

use App\Services\CompanyBackupService;

class CompanyBackupController extends Controller
{
    /**
     * The "Download Your Backup" page under Settings.
     *
     * Admin role only: the button is shown only to users holding the
     * 'download company backup' permission (granted to the Admin /
     * Administrador / Administrator / company roles), and the page
     * itself enforces the same check — hiding the menu item alone
     * would not be security. Super Admin is excluded on purpose:
     * this is the company admins' own-data backup, not the full
     * system backup.
     */
    public function index()
    {
        if (! $this->canDownloadBackup()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $service = new CompanyBackupService(\Auth::user());

        return view('companyBackup.index', [
            'companies' => $service->companies(),
        ]);
    }

    /**
     * Stream the scoped SQL dump as a file download.
     */
    public function download()
    {
        if (! $this->canDownloadBackup()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // A full database can take a while to stream; do not let the
        // default execution limit cut the download off mid-file.
        set_time_limit(0);

        $service = new CompanyBackupService(\Auth::user());

        return response()->streamDownload(function () use ($service) {
            foreach ($service->generate() as $chunk) {
                echo $chunk;
                flush();
            }
        }, $service->filename(), [
            'Content-Type' => 'application/sql',
        ]);
    }

    protected function canDownloadBackup(): bool
    {
        $user = \Auth::user();

        if ($user->type === 'super admin') {
            return false;
        }

        return $user->can('download company backup');
    }
}
