<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Services\Marketing\Recommender;
use Waimart\Services\Marketing\Tracker;

/** Recommendations: learned from real activity, only sellable in-stock products, popular for newcomers. */
final class RecommenderTest extends DbTestCase
{
    private Recommender $r;
    private int $user;
    /** @var array<string,int> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        Recommender::flush();
        Tracker::reset();
        $this->r = new Recommender($this->db);
        $this->user = $this->db->insert('users', ['email' => 'shopper@example.com', 'password_hash' => 'x', 'first_name' => 'Wanjiru', 'last_name' => 'K']);
        foreach (['phones', 'kitchen', 'books'] as $c) {
            $this->cat[$c] = $this->db->insert('categories', ['name' => ucfirst($c), 'slug' => $c . '-' . bin2hex(random_bytes(2))]);
        }
    }

    private function product(string $cat, string $price = '1000.00', int $stock = 5, array $extra = []): int
    {
        $pid = $this->db->insert('products', $extra + ['seller_id' => \Waimart\Services\Sellers::platformId($this->db), 'category_id' => $this->cat[$cat],
            'name' => ucfirst($cat) . ' item ' . bin2hex(random_bytes(2)), 'slug' => $cat . '-' . bin2hex(random_bytes(4))]);
        $this->makeVariant($stock, $price, $pid);
        return $pid;
    }

    private function view(int $productId, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->db->insert('shop_events', ['user_id' => $this->user, 'event' => 'product_viewed', 'product_id' => $productId]);
        }
    }

    private function ids(array $cards): array
    {
        return array_map(fn ($c) => (int) $c['id'], $cards);
    }

    public function testNewcomersGetPopularAndActiveShoppersGetTheirInterests(): void
    {
        $phones = [$this->product('phones'), $this->product('phones'), $this->product('phones')];
        $kitchen = [$this->product('kitchen'), $this->product('kitchen')];
        $fresh = $this->r->forYou($this->user, null, 5);
        $this->assertFalse($fresh['personal'], 'no activity yet: popular products');
        $this->assertNotEmpty($fresh['cards']);

        $this->view($kitchen[0], 4);
        $this->view($kitchen[1], 2);
        Recommender::flush();
        $rec = $this->r->forYou($this->user, null, 3);
        $this->assertTrue($rec['personal']);
        $this->assertContains($this->ids($rec['cards'])[0], $kitchen, 'the category the shopper keeps viewing comes first');
        $this->assertSame([], array_diff($this->ids($rec['cards']), array_merge($phones, $kitchen)), 'only real products');
    }

    public function testNothingUnsellableIsEverSuggested(): void
    {
        $ok = $this->product('kitchen');
        $noStock = $this->product('kitchen', '1000.00', 0);
        $unpublished = $this->product('kitchen', '1000.00', 5, ['moderation_status' => 'pending']);
        $inactive = $this->product('kitchen', '1000.00', 5, ['is_active' => 0]);
        $dismissed = $this->product('kitchen');
        $this->view($ok, 3);
        $this->db->insert('shop_events', ['user_id' => $this->user, 'event' => 'rec_dismissed', 'product_id' => $dismissed]);
        Recommender::flush();
        $all = array_merge($this->ids($this->r->forYou($this->user, null, 20)['cards']), $this->ids($this->r->popular(20)),
            $this->ids($this->r->similar(['id' => $ok, 'category_id' => $this->cat['kitchen'], 'brand_id' => null, 'price' => '1000'], 20)));
        foreach ([$noStock => 'out of stock', $unpublished => 'not published', $inactive => 'inactive'] as $id => $why) {
            $this->assertNotContains($id, $all, $why);
        }
        $this->assertNotContains($dismissed, $this->ids($this->r->forYou($this->user, null, 20)['cards']), '"Not interested" is respected');
    }

    public function testSimilarBoughtTogetherAndComplements(): void
    {
        $phone = $this->product('phones', '20000.00');
        $close = $this->product('phones', '22000.00');
        $far = $this->product('phones', '900.00');
        $book = $this->product('books', '500.00');
        $sim = $this->ids($this->r->similar(['id' => $phone, 'category_id' => $this->cat['phones'], 'brand_id' => null, 'price' => '20000'], 5));
        $this->assertSame($close, $sim[0], 'similar price ranks first');
        $this->assertNotContains($phone, $sim, 'never the product itself');
        $this->assertNotContains($book, $sim);

        // Two paid orders with the phone and the book together.
        foreach ([1, 2] as $_) {
            $o = $this->makeOrder((int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$phone]), 1, ['status' => 'confirmed', 'payment_status' => 'verified']);
            $this->db->insert('order_items', ['order_id' => $o['order_id'], 'seller_id' => \Waimart\Services\Sellers::platformId($this->db), 'seller_order_id' => $o['seller_order_id'],
                'product_id' => $book, 'variant_id' => (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$book]), 'sku' => 'B', 'product_name' => 'Book',
                'variant_name' => 'Default', 'unit_price' => '500.00', 'quantity' => 1, 'line_total' => '500.00']);
        }
        $this->assertGreaterThan(0, $this->r->rebuildPairs());
        $this->assertSame([$book], $this->ids($this->r->boughtTogether($phone)));
        $this->assertSame($book, $this->ids($this->r->complements([$phone], 3))[0], 'cart complements start with what is bought together');

        Recommender::shown('cart', 3);
        Recommender::shown('cart', 3);
        Recommender::shown('cart', 0);
        $this->assertSame(2, (int) $this->db->value("SELECT shown FROM rec_impressions WHERE placement = 'cart'"), 'one count per page view, none for an empty row');
    }

    public function testPlacementsCanBeSwitchedOff(): void
    {
        $this->assertTrue(Recommender::enabled('home'));
        Settings::set('recs_home', '0');
        $this->assertFalse(Recommender::enabled('home'));
        Settings::set('recs_home', '1');
        Settings::set('recs_enabled', '0');
        $this->assertFalse(Recommender::enabled('cart'), 'master switch');
        Settings::set('recs_enabled', '1');
    }
}
