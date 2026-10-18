<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\Brand;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SettingsService;

/** Admin → Settings: one page per group (SettingsService::GROUPS), plus export/import. All need settings.manage. */
final class SettingsController extends AdminController
{
    public function index(Request $req): Response
    {
        return Response::redirect('/admin/settings/general');
    }

    public function group(Request $req): Response
    {
        $group = $this->groupParam($req);
        return $this->view('settings/group', [
            'title' => SettingsService::GROUPS[$group][0] . ' — Settings', 'group' => $group,
            'values' => SettingsService::values($group),
            'docModes' => $group === 'marketplace' ? SellerDocumentPolicy::modes() : [],
        ]);
    }

    public function saveGroup(Request $req): Response
    {
        $group = $this->groupParam($req);
        (new SettingsService())->save($req->post, $this->actor(), $group);
        return $this->success(SettingsService::GROUPS[$group][0] . ' settings saved.', '/admin/settings/' . $group);
    }

    /** The full form (every registered setting at once), kept for scripts and older clients. */
    public function update(Request $req): Response
    {
        (new SettingsService())->save($req->post, $this->actor());
        return $this->success('Settings saved.', '/admin/settings');
    }

    public function transfer(Request $req): Response
    {
        return $this->view('settings/transfer', ['title' => 'Export & import — Settings', 'group' => 'transfer']);
    }

    public function export(Request $req): Response
    {
        $data = (new SettingsService())->export($this->actor());
        return Response::json($data)
            ->withHeader('Content-Disposition', 'attachment; filename="' . Brand::slug() . '-settings-' . date('Y-m-d') . '.json"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function import(Request $req): Response
    {
        $f = $req->files['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])
            || (PHP_SAPI !== 'cli' && !is_uploaded_file((string) $f['tmp_name'])) || (int) $f['size'] > 256 * 1024) {
            throw new \Waimart\Core\ValidationException(['file' => 'Choose a settings export file (.json, up to 256 KB).']);
        }
        $result = (new SettingsService())->import((string) file_get_contents((string) $f['tmp_name']), $this->actor());
        $msg = "Settings imported: {$result['changed']} changed.";
        if ($result['ignored']) {
            $msg .= ' Not imported (payment numbers, or unknown in this version): ' . implode(', ', array_slice($result['ignored'], 0, 12)) . '.';
        }
        Session::flash('success', $msg);
        return Response::redirect('/admin/settings/transfer');
    }

    private function groupParam(Request $req): string
    {
        $g = (string) $req->param('group');
        return isset(SettingsService::GROUPS[$g]) ? $g : throw HttpException::notFound();
    }
}
