<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Services\SiteUpdater;

/**
 * Admin → System → Website updates (system.update): upload a .zip of the project, review what will change, install
 * it while the shop stays open, and roll it back. The page drives the work through small JSON steps (see
 * public/assets/js/updater.js) so it can show what is happening and how far along it is.
 */
final class UpdateController extends AdminController
{
    public function index(Request $req): Response
    {
        $u = new SiteUpdater();
        $active = $u->active();
        return $this->view('updates/index', [
            'title' => 'Website updates', 'active' => $active ? self::summary($active) : null, 'history' => $u->history(),
            'maxMb' => (int) (SiteUpdater::MAX_PACKAGE / 1024 / 1024),
        ]);
    }

    public function start(Request $req): Response
    {
        $j = (new SiteUpdater())->begin((string) $req->input('name', ''), (int) $req->input('size', 0), $this->actor());
        return Response::json(self::summary($j));
    }

    public function chunk(Request $req): Response
    {
        $f = $req->files['chunk'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])
            || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name']))) {
            throw ValidationException::withMessage('A part of the upload was lost. Please try again.');
        }
        $j = (new SiteUpdater())->appendChunk((string) $req->param('uuid'), (int) $req->input('index', -1), (string) file_get_contents((string) $f['tmp_name']));
        return Response::json(self::summary($j));
    }

    public function step(Request $req): Response
    {
        return Response::json(self::summary((new SiteUpdater())->step((string) $req->param('uuid'))));
    }

    public function status(Request $req): Response
    {
        return Response::json(self::summary((new SiteUpdater())->journal((string) $req->param('uuid'))));
    }

    public function approve(Request $req): Response
    {
        $r = (new SiteUpdater())->approve((string) $req->param('uuid'), (string) ($req->post['password'] ?? ''), $this->actor());
        return Response::json(self::summary($r['journal']) + ['rescue_token' => $r['rescue_token']]);
    }

    public function cancel(Request $req): Response
    {
        (new SiteUpdater())->cancel((string) $req->param('uuid'), $this->actor());
        if ($req->isAjax()) {
            return Response::json(['ok' => true]);
        }
        return $this->success('Update cancelled. Nothing was changed.', '/admin/updates');
    }

    public function rollback(Request $req): Response
    {
        $r = (new SiteUpdater())->rollback((string) $req->param('uuid'), (string) ($req->post['password'] ?? ''), $this->actor());
        Session::flash($r['ok'] ? 'success' : 'warning', $r['ok']
            ? "Rolled back: {$r['files_restored']} files restored, {$r['files_removed']} removed, {$r['db_statements']} database additions removed."
            : 'Rolled back with warnings: ' . implode(' ', array_slice($r['errors'], 0, 3)));
        return Response::redirect('/admin/updates');
    }

    /** What the page needs from a journal (not the full file lists or any secret). @param array<string,mixed> $j @return array<string,mixed> */
    public static function summary(array $j): array
    {
        $files = $j['files'] ?? ['changed' => [], 'added' => [], 'unchanged' => 0];
        return [
            'uuid' => $j['uuid'], 'status' => $j['status'], 'progress' => $j['progress'], 'busy' => !empty($j['busy']),
            'package' => ['name' => $j['package']['name'], 'size' => $j['package']['size'], 'received' => $j['package']['received']],
            'files' => ['changed' => array_slice($files['changed'], 0, 300), 'added' => array_slice($files['added'], 0, 300),
                        'changed_count' => count($files['changed']), 'added_count' => count($files['added']), 'unchanged' => $files['unchanged'],
                        'skipped' => $j['skipped'] ?? 0],
            'plan' => $j['plan'] ?? [], 'blocked' => $j['blocked'] ?? [], 'log' => array_slice($j['log'] ?? [], -25),
            'final' => in_array($j['status'], SiteUpdater::FINAL, true), 'installing' => in_array($j['status'], SiteUpdater::INSTALL, true),
            'rollback' => $j['rollback'] ?? null,
        ];
    }
}
