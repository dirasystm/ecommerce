<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\AuditLogger;

/** Admin → Audit log: free-text search, page size and pagination (top and bottom), and the export following the search. */
final class AuditLogHttpTest extends FeatureTestCase
{
    public function testSearchAndPagination(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            AuditLogger::log('test.bulk', 'widget', $i, null, ['colour' => $i === 42 ? 'tangerine-orange' : 'grey'], null);
        }
        $this->loginStaff('super_admin');

        $page1 = $this->get('/admin/audit?per=25')['body'];
        $this->assertMatchesRegularExpression('/Showing 1–25 of (\d+)/', $page1);
        $this->assertSame(2, substr_count($page1, 'class="pagination'), 'page links above and below the table');
        $this->assertStringContainsString('aria-label="Pagination (top)"', $page1);
        $this->assertStringContainsString('per=25&amp;page=2', $page1, 'page links keep the filters');
        $this->assertStringContainsString('Showing 26–50', $this->get('/admin/audit?per=25&page=2')['body']);

        // Search looks inside the recorded details, the action, the record and the staff member.
        $hit = $this->get('/admin/audit?q=tangerine')['body'];
        $this->assertStringContainsString('Showing 1–1 of 1 matching “tangerine”', $hit);
        $this->assertStringContainsString('widget #42', $hit);
        $this->assertStringContainsString('Showing 1–1 of 1', $this->get('/admin/audit?q=' . rawurlencode('widget') . '&entity=widget&entity_id=7')['body']);
        $this->assertStringContainsString('of 60', $this->get('/admin/audit?q=test.bulk')['body']);
        $none = $this->get('/admin/audit?q=' . rawurlencode('100%_nothing'))['body'];
        $this->assertStringContainsString('No audit entries match “100%_nothing”', $none, '% and _ are searched literally');

        $csv = $this->get('/admin/audit?q=tangerine&format=csv')['body'];
        $this->assertSame(2, count(array_filter(explode("\n", trim($csv)))), 'header + the one match');
        $this->assertSame(200, $this->get('/admin/audit?per=999&page=999')['status'], 'out-of-range values fall back safely');
    }
}
