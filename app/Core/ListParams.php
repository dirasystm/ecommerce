<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Page, page size, search and sort of a table, read from the query string and validated (the standard for every
 * list in the admin, seller centre and account). Page size defaults to 10 and is one of SIZES; sort keys come from
 * an allowlist mapped to SQL, so nothing from the request reaches SQL except bound values.
 *
 *   $lp = ListParams::from($req, ['name' => 'p.name', 'created' => 'p.created_at'], 'created');
 *   $total = $db->value("SELECT COUNT(*) FROM ... WHERE ...", $params);
 *   $lp->fit($total);
 *   $rows = $db->all("SELECT ... ORDER BY " . $lp->orderSql() . ' ' . $lp->limitSql(), $params);
 */
final class ListParams
{
    public const SIZES = [10, 15, 25, 50];
    public const DEFAULT_SIZE = 10;

    public int $page;
    public int $per;
    public string $q;
    public string $sort;
    public string $dir;
    public int $total = 0;

    /** @param array<string,string> $sorts key => SQL expression */
    private function __construct(private array $sorts)
    {
    }

    /**
     * @param Request|array<string,mixed> $from request or query array
     * @param array<string,string> $sorts allowed sort keys => SQL expression
     */
    public static function from(Request|array $from, array $sorts = [], string $defaultSort = '', string $defaultDir = 'desc'): self
    {
        $q = $from instanceof Request ? $from->query : $from;
        $lp = new self($sorts);
        $page = (string) ($q['page'] ?? '1');
        $lp->page = ctype_digit($page) ? max(1, min(100000, (int) $page)) : 1;
        $per = (int) ($q['per'] ?? self::DEFAULT_SIZE);
        $lp->per = in_array($per, self::SIZES, true) ? $per : self::DEFAULT_SIZE;
        // Search: trimmed, inner whitespace collapsed, bounded length.
        $lp->q = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_string($q['q'] ?? null) ? $q['q'] : '')), 0, 100);
        $sort = is_string($q['sort'] ?? null) ? $q['sort'] : '';
        $lp->sort = isset($sorts[$sort]) ? $sort : $defaultSort;
        $dir = strtolower(is_string($q['dir'] ?? null) ? $q['dir'] : '');
        $lp->dir = in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir;
        return $lp;
    }

    /** "%term%" for LIKE, with the term's own % and _ escaped. */
    public function like(): string
    {
        return '%' . addcslashes($this->q, '%_\\') . '%';
    }

    /** Records the total and moves an out-of-range page (after a search or a deletion) to the last page. */
    public function fit(int $total): self
    {
        $this->total = max(0, $total);
        $this->page = min($this->page, $this->pages());
        return $this;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->per));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->per;
    }

    public function limitSql(): string
    {
        return 'LIMIT ' . $this->per . ' OFFSET ' . $this->offset();
    }

    /** ORDER BY expression from the allowlist (with a stable tie-breaker when given). */
    public function orderSql(string $tieBreaker = ''): string
    {
        $expr = $this->sorts[$this->sort] ?? null;
        if ($expr === null) {
            return $tieBreaker !== '' ? $tieBreaker : '1';
        }
        return $expr . ' ' . strtoupper($this->dir) . ($tieBreaker !== '' ? ', ' . $tieBreaker : '');
    }

    /**
     * Counts, corrects the page and fetches only the current page.
     * @param string $select "SELECT ... " (columns) @param string $from "FROM ... WHERE ..." (no ORDER/LIMIT) @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetch(Database $db, string $select, string $from, array $params = [], string $tieBreaker = ''): array
    {
        $this->fit((int) $db->value("SELECT COUNT(*) $from", $params));
        return $db->all("$select $from ORDER BY " . $this->orderSql($tieBreaker) . ' ' . $this->limitSql(), $params);
    }

    /** Slices an already-bounded array (small lists computed in PHP) to the current page. @template T @param list<T> $rows @return list<T> */
    public function slice(array $rows): array
    {
        $this->fit(count($rows));
        return array_slice($rows, $this->offset(), $this->per);
    }
}
