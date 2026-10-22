<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\AuditLogger;

/** Admin → Audit log: free-text search, page size and pagination (standard table footer), and the export following the search. */
final class AuditLogHttpTest extends FeatureTestCase
{
    public function testSearchAndPagination(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            AuditLogger::log('test.bulk', 'widget', $i, null, ['colour' => $i === 42 ? 'tangerine-orange' : 'grey'], null);
        }
        $this->loginStaff('super_admin');

        $default = $this->get('/admin/audit')['body'];
        $this->assertMatchesRegularExpression('/Showing 1–10 of (\d+) entries/', $default, '10 rows by default');
        $this->assertStringContainsString('data-live-table="audit"', $default, 'live search');
        $page1 = $this->get('/admin/audit?per=25')['body'];
        $this->assertMatchesRegularExpression('/Showing 1–25 of (\d+) entries/', $page1);
        $this->assertSame(1, substr_count($page1, 'class="pagination'), 'page links in the table footer');
        $this->assertStringContainsString('per=25&amp;page=2', $page1, 'page links keep the filters');
        $this->assertStringContainsString('Showing 26–50', $this->get('/admin/audit?per=25&page=2')['body']);
        $this->assertStringContainsString('Showing 1–15 of', $this->get('/admin/audit?per=15')['body'], '15-row option');

        // Search looks inside the recorded details, the action, the record and the staff member.
        $hit = $this->get('/admin/audit?q=tangerine')['body'];
        $this->assertStringContainsString('Showing 1–1 of 1 entries', $hit);
        $this->assertStringContainsString('widget #42', $hit);
        $this->assertStringContainsString('Showing 1–1 of 1', $this->get('/admin/audit?q=' . rawurlencode('widget') . '&entity=widget&entity_id=7')['body']);
        $this->assertStringContainsString('of 60', $this->get('/admin/audit?q=test.bulk')['body']);
        $this->assertStringContainsString('of 60', $this->get('/admin/audit?q=' . rawurlencode('  test.bulk  '))['body'], 'surrounding spaces ignored');
        $none = $this->get('/admin/audit?q=' . rawurlencode('100%_nothing'))['body'];
        $this->assertStringContainsString('No audit entries match these filters.', $none, '% and _ are searched literally');

        $csv = $this->get('/admin/audit?q=tangerine&format=csv')['body'];
        $this->assertSame(2, count(array_filter(explode("\n", trim($csv)))), 'header + the one match');
        $last = $this->get('/admin/audit?q=test.bulk&page=999');
        $this->assertSame(200, $last['status'], 'out-of-range page moves to the last page');
        $this->assertStringContainsString('Showing 51–60 of 60', $last['body']);
        $this->assertStringContainsString('Showing 1–10', $this->get('/admin/audit?per=999')['body'], 'unsupported page size falls back to 10');
    }
}
