<?php

/**
 * Menus exporter tests. They stub Polylang and WordPress, and one case
 * feeds a fake wpdb so the database fallback can run without WordPress.
 *
 * @package MigrationManager
 */

namespace WMM\Tolka;

use PHPUnit\Framework\TestCase;

$class_file = dirname(__DIR__, 2) . '/includes/tolka/class-polylang-menus.php';
if (! class_exists(Polylang_Menus::class, false) && is_readable($class_file)) {
    require_once $class_file;
}

/**
 * Customer-facing menu export.
 */
class Polylang_Menus_Test extends TestCase
{
    /**
     * Drop a database handle left by another test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function test_class_is_available(): void
    {
        if (! class_exists(Polylang_Menus::class, false)) {
            $this->markTestSkipped('Polylang_Menus is not available.');
        }
        $this->assertTrue(class_exists(Polylang_Menus::class, false));
    }

    public function test_export_without_wordpress_returns_a_finished_error(): void
    {
        if (! class_exists(Polylang_Menus::class, false)) {
            $this->markTestSkipped('Polylang_Menus is not available.');
        }
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        unset($GLOBALS['wpdb']);
        $result = (new Polylang_Menus())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('WordPress is not loaded, so menus could not be read.'),
            $result['errors']
        );
    }

    public function test_inactive_polylang_without_data_returns_a_finished_error(): void
    {
        $result = (new Polylang_Menus_Fixture())->export();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Polylang is not active, and no menus or theme locations were found. Activate Polylang and run the preview again.'),
            $result['errors']
        );
    }

    public function test_theme_location_with_no_menu(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'languages' => array('en', 'sv'),
            'default_language' => 'en',
            'registered' => array(
                'footer' => 'Footer',
            ),
        ));

        $result = $fixture->run();
        $footer = $this->location($result, 'footer');

        $this->assertSame(array(), $result['errors']);
        $this->assertSame('footer', $footer['location']);
        $this->assertSame('Footer', $footer['label']);
        $this->assertSame(array('en', 'sv'), array_keys($footer['translations']));
        foreach (array('en', 'sv') as $language) {
            $this->assertSame(0, $footer['translations'][$language]['menu_id']);
            $this->assertSame('', $footer['translations'][$language]['name']);
            $this->assertSame(array(), $footer['translations'][$language]['items']);
            $this->assertSame(0, $footer['translations'][$language]['link_count']);
        }
    }

    public function test_nested_menu_item(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(4 => 'Main'),
            'slugs' => array(4 => 'main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 4),
                    ),
                ),
            ),
            'items' => array(
                4 => array(
                    array(
                        'id' => 9,
                        'parent' => 8,
                        'title' => 'Child',
                        'url' => 'https://example.com/child',
                        'object' => 'custom',
                        'object_id' => 16,
                        'position' => 2,
                    ),
                    array(
                        'id' => 8,
                        'parent' => 0,
                        'title' => 'Parent',
                        'url' => 'https://example.com/parent',
                        'object' => 'page',
                        'object_id' => 15,
                        'position' => 1,
                    ),
                ),
            ),
        ));

        $result = $fixture->run();
        $items = $this->location($result, 'primary')['translations']['en']['items'];

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $items);
        $this->assertSame('Parent', $items[0]['title']);
        $this->assertSame('https://example.com/parent', $items[0]['url']);
        $this->assertSame('page', $items[0]['object']);
        $this->assertSame(15, $items[0]['object_id']);
        $this->assertCount(1, $items[0]['children']);

        $child = $items[0]['children'][0];
        $this->assertSame('Child', $child['title']);
        $this->assertSame('https://example.com/child', $child['url']);
        $this->assertSame('custom', $child['object']);
        $this->assertSame(16, $child['object_id']);
        $this->assertSame(8, $child['parent']);
        $this->assertSame(array(), $child['children']);
        $this->assertSame(2, $this->location($result, 'primary')['translations']['en']['link_count']);
    }

    public function test_one_menu_assigned_to_two_locations_appears_under_both(): void
    {
        $items = array(
            10 => array(
                array(
                    'id' => 1,
                    'parent' => 0,
                    'title' => 'Home',
                    'url' => 'https://example.com/',
                    'object' => 'custom',
                    'object_id' => 1,
                    'position' => 1,
                ),
            ),
        );
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'default_language' => 'en',
            'registered' => array(
                'primary' => 'Primary',
                'footer' => 'Footer',
            ),
            'menus' => array(10 => 'Shared'),
            'slugs' => array(10 => 'shared'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                        'footer' => array('en' => 10),
                    ),
                ),
            ),
            'items' => $items,
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $primary = $this->location($second, 'primary')['translations']['en'];
        $footer = $this->location($second, 'footer')['translations']['en'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(1, $counts['primary']);
        $this->assertSame(1, $counts['footer']);
        $this->assertSame(10, $primary['menu_id']);
        $this->assertSame(10, $footer['menu_id']);
        $this->assertSame('Shared', $primary['name']);
        $this->assertSame('Shared', $footer['name']);
        $this->assertSame('Home', $primary['items'][0]['title']);
        $this->assertSame('https://example.com/', $primary['items'][0]['url']);
        $this->assertSame('Home', $footer['items'][0]['title']);
        $this->assertSame('https://example.com/', $footer['items'][0]['url']);

        $from_theme = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'default_language' => 'en',
            'menus' => array(10 => 'Shared'),
            'term_languages' => array(10 => 'en'),
            'theme_locations' => array(
                'primary' => 10,
                'footer' => 10,
            ),
            'items' => $items,
        ));
        $theme_result = $from_theme->export();

        $this->assertSame(10, $this->location($theme_result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(10, $this->location($theme_result, 'footer')['translations']['en']['menu_id']);
        $this->assertSame('Home', $this->location($theme_result, 'footer')['translations']['en']['items'][0]['title']);
    }

    public function test_menu_item_with_empty_url_keeps_its_title(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(6 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 6),
                    ),
                ),
            ),
            'items' => array(
                6 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'About',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 5,
                        'position' => 1,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 1,
                        'title' => 'Team',
                        'url' => '   ',
                        'object' => 'custom',
                        'object_id' => 0,
                        'position' => 2,
                    ),
                ),
            ),
            'navigations' => array(
                array(
                    'id' => 50,
                    'name' => 'Header',
                    'slug' => 'header',
                    'language' => 'en',
                    'content' => '<!-- wp:navigation-link {"label":"Om oss","url":""} /-->',
                ),
            ),
        ));

        $items = $this->location($fixture->export(), 'primary')['translations']['en']['items'];
        $navigation = $this->location($fixture->export(), 'navigation:header')['translations']['en']['items'][0];

        $this->assertCount(1, $items);
        $this->assertSame('About', $items[0]['title']);
        $this->assertSame('', $items[0]['url']);
        $this->assertSame('page', $items[0]['object']);
        $this->assertSame(5, $items[0]['object_id']);
        $this->assertSame('Team', $items[0]['children'][0]['title']);
        $this->assertSame('', $items[0]['children'][0]['url']);
        $this->assertSame('custom', $items[0]['children'][0]['object']);
        $this->assertSame('Om oss', $navigation['title']);
        $this->assertSame('', $navigation['url']);
    }

    public function test_database_menu_on_two_locations_keeps_an_empty_url(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menu_posts[10] = array(
            array(
                'id' => 8,
                'title' => 'About',
                'attr_title' => '',
                'description' => '',
                'position' => 1,
            ),
            array(
                'id' => 9,
                'title' => 'Team',
                'attr_title' => '',
                'description' => '',
                'position' => 2,
            ),
        );
        $database->meta[8] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'page',
            '_menu_item_object_id' => '5',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => '',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[9] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '0',
            '_menu_item_menu_item_parent' => '8',
            '_menu_item_url' => '',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('en' => 10),
                    'footer' => array('en' => 10),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $first = $menus->export();
            $second = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $primary = $this->location($second, 'primary')['translations']['en'];
        $footer = $this->location($second, 'footer')['translations']['en'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(1, $counts['primary']);
        $this->assertSame(1, $counts['footer']);
        $this->assertSame(10, $primary['menu_id']);
        $this->assertSame(10, $footer['menu_id']);
        $this->assertSame('About', $primary['items'][0]['title']);
        $this->assertSame('', $primary['items'][0]['url']);
        $this->assertSame('Team', $primary['items'][0]['children'][0]['title']);
        $this->assertSame('', $primary['items'][0]['children'][0]['url']);
        $this->assertSame('About', $footer['items'][0]['title']);
        $this->assertSame('', $footer['items'][0]['url']);
        $this->assertSame('Team', $footer['items'][0]['children'][0]['title']);
        $this->assertSame('', $footer['items'][0]['children'][0]['url']);
    }

    public function test_export_is_callable_on_an_instance(): void
    {
        $menus = new Polylang_Menus();
        $result = $menus->export();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['items']);
        $this->assertIsArray($result['errors']);
    }

    public function test_menu_item_with_empty_title_keeps_its_url(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(6 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 6),
                    ),
                ),
            ),
            'items' => array(
                6 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => '',
                        'url' => 'https://example.com/blank',
                        'object' => 'custom',
                        'object_id' => 0,
                        'position' => 1,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 0,
                        'title' => 'Parent',
                        'url' => 'https://example.com/parent',
                        'object' => 'page',
                        'object_id' => 9,
                        'position' => 2,
                    ),
                    array(
                        'id' => 3,
                        'parent' => 2,
                        'title' => '',
                        'url' => 'https://example.com/child-blank',
                        'object' => 'custom',
                        'object_id' => 0,
                        'position' => 3,
                    ),
                ),
            ),
        ));

        $result = $fixture->export();
        $items = $this->location($result, 'primary')['translations']['en']['items'];

        $this->assertCount(2, $items);
        $this->assertSame('', $items[0]['title']);
        $this->assertSame('https://example.com/blank', $items[0]['url']);
        $this->assertSame('custom', $items[0]['object']);
        $this->assertSame(0, $items[0]['object_id']);
        $this->assertSame('', $items[1]['children'][0]['title']);
        $this->assertSame('https://example.com/child-blank', $items[1]['children'][0]['url']);
        $this->assertSame('custom', $items[1]['children'][0]['object']);
    }

    public function test_html_in_a_title_is_kept_and_null_bytes_are_removed(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(6 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 6),
                    ),
                ),
            ),
            'items' => array(
                6 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => "<em>Fish &amp; Chips</em>\0",
                        'url' => "https://example.com/a\0b",
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 1,
                        'title' => "Tea\0m",
                        'url' => "https://example.com/team\0",
                        'object' => 'page',
                        'object_id' => 4,
                        'position' => 2,
                    ),
                ),
            ),
        ));

        $items = $this->location($fixture->export(), 'primary')['translations']['en']['items'];

        $this->assertSame('<em>Fish & Chips</em>', $items[0]['title']);
        $this->assertSame('https://example.com/ab', $items[0]['url']);
        $this->assertStringContainsString('<em>', $items[0]['title']);
        $this->assertStringContainsString('</em>', $items[0]['title']);
        $this->assertSame('Team', $items[0]['children'][0]['title']);
        $this->assertSame('https://example.com/team', $items[0]['children'][0]['url']);
    }

    public function test_navigation_title_keeps_html_and_drops_null_bytes(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'default_language' => 'en',
            'languages' => array('en'),
            'navigations' => array(
                array(
                    'id' => 30,
                    'name' => 'Header',
                    'slug' => 'header',
                    'language' => 'en',
                    'content' => '<!-- wp:navigation-link {"label":"<em>Ho\u0000me</em>","url":"https://example.com/\u0000x"} /-->',
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $item = $this->location($second, 'navigation:header')['translations']['en']['items'][0];

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame('<em>Home</em>', $item['title']);
        $this->assertSame('https://example.com/x', $item['url']);
        $this->assertStringNotContainsString("\0", $item['title']);
        $this->assertStringNotContainsString("\0", $item['url']);
    }

    public function test_swedish_letters_are_kept_in_titles_and_urls(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'sv',
            'languages' => array('sv'),
            'registered' => array(
                'kväll' => 'Kvällsmeny',
            ),
            'menus' => array(
                6 => 'Förrätter',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'kväll' => array('sv' => 6),
                    ),
                ),
            ),
            'items' => array(
                6 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'ÅÄÖ Fågel &amp; öl',
                        'url' => "https://example.com/rätt/öl\0",
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 1,
                        'title' => 'R&auml;ksm&ouml;rg&aring;s',
                        'url' => 'https://example.com/r%C3%A4tt',
                        'object' => 'page',
                        'object_id' => 3,
                        'position' => 2,
                    ),
                ),
            ),
            'navigations' => array(
                array(
                    'id' => 40,
                    'name' => 'Sidhuvud',
                    'slug' => 'smörgås',
                    'language' => 'sv',
                    'content' => '<!-- wp:navigation-link {"label":"Smörgås","url":"https://example.com/smörgås"} /-->',
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $menu = $this->location($second, 'kväll');
        $link = $menu['translations']['sv']['items'][0];
        $child = $link['children'][0];
        $navigation = $this->location($second, 'navigation:smörgås')['translations']['sv']['items'][0];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame($first, $second);
        $this->assertSame(1, $counts['kväll']);
        $this->assertSame(1, $counts['navigation:smörgås']);
        $this->assertSame('Kvällsmeny', $menu['label']);
        $this->assertSame('Förrätter', $menu['translations']['sv']['name']);
        $this->assertSame('ÅÄÖ Fågel & öl', $link['title']);
        $this->assertSame('https://example.com/rätt/öl', $link['url']);
        $this->assertSame('Räksmörgås', $child['title']);
        $this->assertSame('https://example.com/r%C3%A4tt', $child['url']);
        $this->assertSame('Smörgås', $navigation['title']);
        $this->assertSame('https://example.com/smörgås', $navigation['url']);
    }

    public function test_database_title_keeps_swedish_letters(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menus = array(
            array(
                'id' => 10,
                'name' => 'Förrätter',
                'slug' => 'forratter',
            ),
        );
        $database->menu_posts[10] = array(
            array(
                'id' => 8,
                'title' => 'ÅÄÖ Fågel &amp; öl',
                'attr_title' => '',
                'description' => '',
                'position' => 1,
            ),
        );
        $database->meta[8] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '0',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => 'https://example.com/rätt/öl',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'sv',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('sv' => 10),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $first = $menus->export();
            $second = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $link = $this->location($second, 'primary')['translations']['sv']['items'][0];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(1, $counts['primary']);
        $this->assertSame('Förrätter', $this->location($second, 'primary')['translations']['sv']['name']);
        $this->assertSame('ÅÄÖ Fågel & öl', $link['title']);
        $this->assertSame('https://example.com/rätt/öl', $link['url']);
    }

    public function test_second_export_does_not_duplicate_locations(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en'),
            'registered' => array(
                'primary' => 'Primary',
                'footer' => 'Footer',
            ),
            'menus' => array(
                4 => 'Main',
                7 => 'Loose',
                8 => 'Other',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 4),
                    ),
                ),
            ),
            'term_languages' => array(
                7 => 'en',
                8 => 'sv',
            ),
            'navigations' => array(
                array(
                    'id' => 20,
                    'name' => 'Header',
                    'slug' => 'header',
                    'language' => 'en',
                    'content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.com/"} /-->',
                ),
            ),
            'items' => array(
                4 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Home',
                        'url' => '/',
                        'object' => 'custom',
                        'object_id' => 1,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame($first, $second);
        $this->assertSame(array('primary', 'footer', '', '', 'navigation:header'), $this->locations($second));

        $counts = array_count_values($this->locations($second));
        $this->assertSame(1, $counts['primary']);
        $this->assertSame(1, $counts['footer']);
        $this->assertSame(1, $counts['navigation:header']);
        $this->assertSame(2, $counts['']);
    }

    public function test_polylang_inactive(): void
    {
        $inactive = $this->fixture(array(
            'active' => false,
            'wordpress' => true,
        ));
        $empty = $inactive->run();

        $this->assertSame(array(), $empty['items']);
        $this->assertNotEmpty($empty['errors']);
        $this->assertIsString($empty['errors'][0]);
        $this->assertStringContainsString('Polylang is not active', $empty['errors'][0]);
        $this->assertStringEndsWith('.', $empty['errors'][0]);

        $stored = $this->fixture(array(
            'active' => false,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(3 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 3),
                    ),
                ),
            ),
            'items' => array(
                3 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Home',
                        'url' => '/',
                        'object' => 'custom',
                        'object_id' => 1,
                    ),
                ),
            ),
        ));
        $result = $stored->run();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(3, $this->location($result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame('Home', $this->location($result, 'primary')['translations']['en']['items'][0]['title']);
        $this->assertSame('/', $this->location($result, 'primary')['translations']['en']['items'][0]['url']);
        $this->assertSame('custom', $this->location($result, 'primary')['translations']['en']['items'][0]['object']);
        $this->assertSame(1, $this->location($result, 'primary')['translations']['en']['items'][0]['object_id']);
    }

    public function test_database_row_with_empty_title_keeps_its_url(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menu_posts[10] = array(
            array(
                'id' => 8,
                'title' => '',
                'attr_title' => '',
                'description' => '',
                'position' => 1,
            ),
            array(
                'id' => 9,
                'title' => '',
                'attr_title' => '',
                'description' => '',
                'position' => 2,
            ),
        );
        $database->meta[8] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '0',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => 'https://example.com/blank',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[9] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '0',
            '_menu_item_menu_item_parent' => '8',
            '_menu_item_url' => 'https://example.com/child-blank',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('en' => 10),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $result = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $blank = null;
        foreach ($this->location($result, 'primary')['translations']['en']['items'] as $item) {
            if ($item['url'] === 'https://example.com/blank') {
                $blank = $item;
            }
        }

        $this->assertNotNull($blank);
        $this->assertSame('', $blank['title']);
        $this->assertSame('custom', $blank['object']);
        $this->assertCount(1, $blank['children']);
        $this->assertSame('', $blank['children'][0]['title']);
        $this->assertSame('https://example.com/child-blank', $blank['children'][0]['url']);
    }

    public function test_groups_locations_and_nests_items(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('sv', 'en'),
            'registered' => array(
                'primary' => 'Primary',
                'footer' => 'Footer',
                'primary___sv' => 'Primary Swedish',
            ),
            'options' => array(
                'default_lang' => 'en',
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array(
                            'en' => 10,
                            'sv' => ' 11 ',
                        ),
                    ),
                    'other' => array(
                        'primary' => array(
                            'en' => 99,
                        ),
                    ),
                ),
            ),
            'menus' => array(
                10 => 'Fish &amp; Chips',
                11 => 'Fisk',
            ),
            'slugs' => array(
                10 => 'fish-chips',
                11 => 'fisk',
            ),
            'items' => array(
                10 => array(
                    array(
                        'id' => 2,
                        'parent' => 1,
                        'title' => 'About',
                        'url' => 'https://example.com/about',
                        'object' => 'page',
                        'object_id' => '5',
                        'type' => 'post_type',
                        'position' => 2,
                        'description' => 'Our &amp; story',
                    ),
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Home',
                        'url' => 'https://example.com/',
                        'object' => 'custom',
                        'object_id' => 1,
                        'type' => 'custom',
                        'position' => 1,
                        'classes' => array('home', ''),
                        'target' => '_blank',
                        'xfn' => 'nofollow',
                        'attr_title' => 'Welcome',
                    ),
                    array(
                        'id' => 3,
                        'parent' => 2,
                        'title' => 'Team',
                        'url' => 'https://example.com/team',
                        'object' => 'page',
                        'object_id' => 6,
                        'position' => 3,
                        'classes' => 'team current',
                    ),
                ),
                11 => array(
                    array(
                        'id' => 4,
                        'parent' => 0,
                        'title' => 'Hem',
                        'url' => '#pll_switcher',
                        'object' => 'custom',
                        'object_id' => 4,
                        'position' => 1,
                    ),
                ),
            ),
        ));

        $result = $fixture->run();
        $again = $fixture->run();

        $this->assertSame(array(), $result['errors']);
        $this->assertSame($result, $again);
        $this->assertSame(array('primary', 'footer'), $this->locations($result));
        $this->assertNotContains('primary___sv', $this->locations($result));

        $primary = $this->location($result, 'primary');
        $this->assertSame('Primary', $primary['label']);
        $this->assertSame('Footer', $this->location($result, 'footer')['label']);
        $this->assertSame(array('en', 'sv'), array_keys($primary['translations']));

        $english = $primary['translations']['en'];
        $this->assertSame(10, $english['menu_id']);
        $this->assertSame('menu', $english['kind']);
        $this->assertSame(3, $english['link_count']);
        $this->assertFalse($english['items'][0]['switcher']);
        $this->assertSame('Fish & Chips', $english['name']);
        $this->assertSame('fish-chips', $english['slug']);
        $this->assertSame('Home', $english['items'][0]['title']);
        $this->assertSame('https://example.com/', $english['items'][0]['url']);
        $this->assertSame('custom', $english['items'][0]['object']);
        $this->assertSame(1, $english['items'][0]['object_id']);
        $this->assertSame(array('home'), $english['items'][0]['classes']);
        $this->assertSame('_blank', $english['items'][0]['target']);
        $this->assertSame('nofollow', $english['items'][0]['xfn']);
        $this->assertSame('Welcome', $english['items'][0]['attr_title']);
        $this->assertSame(array(), $english['items'][0]['children'][0]['children'][0]['children']);
        $this->assertSame('About', $english['items'][0]['children'][0]['title']);
        $this->assertSame(5, $english['items'][0]['children'][0]['object_id']);
        $this->assertSame('Our & story', $english['items'][0]['children'][0]['description']);
        $this->assertSame('Team', $english['items'][0]['children'][0]['children'][0]['title']);
        $this->assertSame(array('team', 'current'), $english['items'][0]['children'][0]['children'][0]['classes']);
        $this->assertSame(1, $english['items'][0]['children'][0]['parent']);
        $this->assertSame(11, $primary['translations']['sv']['menu_id']);
        $this->assertSame(1, $primary['translations']['sv']['link_count']);
        $this->assertSame('#pll_switcher', $primary['translations']['sv']['items'][0]['url']);
        $this->assertTrue($primary['translations']['sv']['items'][0]['switcher']);
        $this->assertSame(0, $this->location($result, 'footer')['translations']['en']['link_count']);

        $footer = $this->location($result, 'footer');
        $this->assertSame(0, $footer['translations']['en']['menu_id']);
        $this->assertSame('', $footer['translations']['en']['name']);
        $this->assertSame(array(), $footer['translations']['en']['items']);
        $this->assertSame(0, $footer['translations']['sv']['menu_id']);
        $this->assertArrayNotHasKey(99, $this->menu_ids($result));
    }

    public function test_locations_with_no_menu_when_polylang_is_inactive(): void
    {
        $fixture = $this->fixture(array(
            'active' => false,
            'languages' => array('en'),
            'registered' => array(
                'primary' => 'Primary',
            ),
        ));

        $result = $fixture->run();
        $primary = $this->location($result, 'primary');

        $this->assertSame(0, $primary['translations']['en']['menu_id']);
        $this->assertSame(array(), $primary['translations']['en']['items']);
        $this->assertSame(
            array('Polylang is not active. Theme locations were listed, and no menu is assigned to them yet.'),
            $result['errors']
        );
    }

    public function test_orphans_cycles_and_sibling_order_stay_in_the_tree(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'languages' => array('en'),
            'default_language' => 'en',
            'registered' => array('primary' => 'Primary'),
            'options' => array(
                'nav_menus' => array(
                    'theme' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'stylesheet' => 'theme',
            'menus' => array(10 => 'Main'),
            'items' => array(
                10 => array(
                    array('id' => 4, 'parent' => 99, 'title' => 'Orphan', 'url' => '/orphan', 'object' => 'custom', 'object_id' => 4, 'position' => 4),
                    array('id' => 1, 'parent' => 2, 'title' => 'Cycle A', 'url' => '/a', 'object' => 'custom', 'object_id' => 1, 'position' => 1),
                    array('id' => 2, 'parent' => 1, 'title' => 'Cycle B', 'url' => '/b', 'object' => 'custom', 'object_id' => 2, 'position' => 2),
                    array('id' => 5, 'parent' => 5, 'title' => 'Self', 'url' => '/self', 'object' => 'custom', 'object_id' => 5, 'position' => 5),
                    array('id' => 30, 'parent' => 0, 'title' => 'Third', 'url' => '/3', 'object' => 'custom', 'object_id' => 30, 'position' => 8),
                    array('id' => 10, 'parent' => 0, 'title' => 'First', 'url' => '/1', 'object' => 'custom', 'object_id' => 10, 'position' => 6),
                    array('id' => 20, 'parent' => 0, 'title' => 'Second', 'url' => '/2', 'object' => 'custom', 'object_id' => 20, 'position' => 7),
                ),
            ),
        ));

        $items = $this->location($fixture->run(), 'primary')['translations']['en']['items'];
        $titles = array();
        foreach ($items as $item) {
            $titles[] = $item['title'];
        }

        $this->assertSame(array('Cycle A', 'Orphan', 'Self', 'First', 'Second', 'Third'), $titles);
        $this->assertSame('Cycle B', $items[0]['children'][0]['title']);
        $this->assertSame(array(), $items[0]['children'][0]['children']);
        $this->assertSame('/orphan', $items[1]['url']);
        $this->assertSame(99, $items[1]['parent']);
        $this->assertSame(0, $items[2]['parent']);
        $this->assertSame(array(), $items[2]['children']);
        $this->assertSame(1, $this->count_titles($items, 'Cycle A'));
        $this->assertSame(1, $this->count_titles($items, 'Cycle B'));
    }

    public function test_explicit_empty_language_is_not_replaced_by_a_sibling(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en', 'sv'),
            'default_language' => 'en',
            'menus' => array(
                10 => 'English',
                11 => 'Swedish',
            ),
            'term_translations' => array(
                10 => array('en' => 10, 'sv' => 11),
                11 => array('en' => 10, 'sv' => 11),
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array(
                            'en' => 10,
                            'sv' => 0,
                        ),
                    ),
                ),
            ),
        ));

        $result = $fixture->run();
        $primary = $this->location($result, 'primary');

        $this->assertSame(10, $primary['translations']['en']['menu_id']);
        $this->assertSame(0, $primary['translations']['sv']['menu_id']);
        $this->assertSame('Swedish', $this->location($result, '')['translations']['sv']['name']);
        $this->assertSame(11, $this->location($result, '')['translations']['sv']['menu_id']);
        $this->assertSame('Not assigned to a theme location', $this->location($result, '')['label']);
    }

    public function test_translation_sibling_joins_its_location(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en', 'sv'),
            'menus' => array(
                10 => 'English',
                11 => 'Swedish',
            ),
            'term_translations' => array(
                10 => array('en' => 10, 'sv' => 11),
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
        ));

        $joined = $fixture->run();
        $this->assertSame(11, $this->location($joined, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame(array('primary'), $this->locations($joined));

        $kept = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en', 'sv'),
            'menus' => array(
                10 => 'English',
                11 => 'Swedish',
            ),
            'term_translations' => array(
                10 => array('en' => 10, 'sv' => 11),
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                        'footer' => array('sv' => 11),
                    ),
                ),
            ),
        ));
        $kept_result = $kept->run();

        $this->assertSame(0, $this->location($kept_result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame(11, $this->location($kept_result, 'footer')['translations']['sv']['menu_id']);
        $this->assertSame(0, $this->location($kept_result, 'footer')['translations']['en']['menu_id']);
    }

    public function test_active_theme_wins_and_other_themes_are_a_fallback(): void
    {
        $active = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(
                10 => 'Current',
                99 => 'Old',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                    'other' => array(
                        'primary' => array('en' => 99),
                    ),
                ),
            ),
        ));
        $active_result = $active->run();
        $this->assertSame(10, $this->location($active_result, 'primary')['translations']['en']['menu_id']);
        $this->assertNotContains('other:primary', $this->locations($active_result));

        $fallback = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(
                2 => 'Older',
                3 => 'Newer',
            ),
            'options' => array(
                'nav_menus' => array(
                    'older' => array(
                        'primary' => array('en' => 2),
                    ),
                    'newer' => array(
                        'primary' => array('en' => 3),
                    ),
                ),
            ),
        ));
        $fallback_result = $fallback->run();
        $this->assertSame(2, $this->location($fallback_result, 'older:primary')['translations']['en']['menu_id']);
        $this->assertSame(3, $this->location($fallback_result, 'newer:primary')['translations']['en']['menu_id']);
        $this->assertSame('primary (older)', $this->location($fallback_result, 'older:primary')['label']);
        $this->assertSame('primary (newer)', $this->location($fallback_result, 'newer:primary')['label']);
    }

    public function test_theme_mod_fills_locations_the_polylang_map_never_saved(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'default_language' => 'en',
            'registered' => array(
                'primary' => 'Primary',
                'footer' => 'Footer',
            ),
            'menus' => array(
                10 => 'Main',
                11 => 'Swedish',
                12 => 'Footer menu',
            ),
            'term_languages' => array(
                12 => 'en',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'theme_locations' => array(
                'primary' => 99,
                'primary___sv' => 11,
                'footer' => 12,
            ),
        ));

        $result = $fixture->run();

        $this->assertSame(10, $this->location($result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(11, $this->location($result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame(12, $this->location($result, 'footer')['translations']['en']['menu_id']);
        $this->assertSame('Footer menu', $this->location($result, 'footer')['translations']['en']['name']);
        $this->assertArrayNotHasKey(99, $this->menu_ids($result));
    }

    public function test_explicit_empty_slot_is_not_filled_from_the_theme_mod(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en', 'sv'),
            'menus' => array(
                10 => 'English',
                11 => 'Swedish',
            ),
            'term_languages' => array(
                11 => 'sv',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array(
                            'en' => 10,
                            'sv' => 0,
                        ),
                    ),
                ),
            ),
            'theme_locations' => array(
                'primary___sv' => 11,
            ),
        ));

        $result = $fixture->run();

        $this->assertSame(0, $this->location($result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame(11, $this->location($result, '')['translations']['sv']['menu_id']);
    }

    public function test_empty_language_switcher_uses_the_languages_title(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(10 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'items' => array(
                10 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => '',
                        'url' => '#pll_switcher',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 0,
                        'title' => '',
                        'url' => '/spacer',
                        'object' => 'custom',
                        'object_id' => 2,
                        'position' => 2,
                    ),
                ),
            ),
        ));

        $items = $this->location($fixture->run(), 'primary')['translations']['en']['items'];

        $this->assertSame('Languages', $items[0]['title']);
        $this->assertTrue($items[0]['switcher']);
        $this->assertSame('', $items[1]['title']);
        $this->assertFalse($items[1]['switcher']);
        $this->assertSame('/spacer', $items[1]['url']);
    }

    public function test_unpublished_links_are_reported_and_left_out(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(10 => 'Main'),
            'skipped' => array(10 => 1),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'items' => array(
                10 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Home',
                        'url' => '/',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                ),
            ),
        ));

        $result = $fixture->run();

        $this->assertSame('Home', $this->location($result, 'primary')['translations']['en']['items'][0]['title']);
        $this->assertSame(1, $this->location($result, 'primary')['translations']['en']['link_count']);
        $this->assertSame(
            array('The "Main" menu (en) has 1 link that is not published. That link was left out of the export.'),
            $result['errors']
        );
    }

    public function test_theme_mod_locations_are_split_by_language(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en', 'sv'),
            'default_language' => 'en',
            'registered' => array(
                'footer' => 'Footer',
            ),
            'theme_locations' => array(
                'primary' => 10,
                'primary___sv' => 11,
            ),
            'menus' => array(
                10 => 'English',
                11 => 'Swedish',
            ),
            'term_languages' => array(
                10 => 'en',
                11 => 'sv',
            ),
        ));

        $result = $fixture->run();

        $this->assertSame(array('footer', 'primary'), $this->locations($result));
        $this->assertSame(10, $this->location($result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(11, $this->location($result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame(0, $this->location($result, 'footer')['translations']['en']['menu_id']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_missing_menu_and_invalid_assignments_use_finished_sentences(): void
    {
        $missing = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 99),
                    ),
                ),
            ),
        ));
        $missing_result = $missing->run();
        $this->assertSame(99, $this->location($missing_result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(array(), $this->location($missing_result, 'primary')['translations']['en']['items']);
        $this->assertSame(
            array('Menu 99, assigned to the "primary" location for en, no longer exists, so that language was exported without items.'),
            $missing_result['errors']
        );

        $empty = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(5 => ''),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 5),
                    ),
                ),
            ),
            'items' => array(5 => array()),
        ));
        $this->assertSame(array(), $empty->run()['errors']);

        $corrupt = $this->fixture(array(
            'active' => false,
            'languages' => array('en'),
            'registered' => array('primary' => 'Primary'),
            'options' => array(
                'nav_menus' => 'bad',
            ),
        ));
        $corrupt_result = $corrupt->run();
        $this->assertSame('primary', $corrupt_result['items'][0]['location']);
        $this->assertSame(
            array(
                'Polylang menu assignments could not be read because the stored nav_menus value is invalid. Theme locations are still listed.',
                'Polylang is not active. Theme locations were listed, and no menu is assigned to them yet.',
            ),
            $corrupt_result['errors']
        );
    }

    public function test_many_missing_menus_stop_after_twenty_sentences(): void
    {
        $assignments = array();
        for ($index = 1; $index <= 21; $index++) {
            $assignments['loc-' . $index] = array('en' => 1000 + $index);
        }
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => $assignments,
                ),
            ),
        ));

        $errors = $fixture->run()['errors'];

        $this->assertCount(21, $errors);
        $this->assertSame(
            'More than 20 assigned menus are missing. The rest were exported without items.',
            $errors[20]
        );
        $this->assertStringContainsString('Menu 1001', $errors[0]);
        $this->assertStringContainsString('Menu 1020', $errors[19]);
        $joined = implode("\n", $errors);
        $this->assertStringNotContainsString('Menu 1021', $joined);
    }

    public function test_read_failure_does_not_leak_the_exception(): void
    {
        $fixture = new Polylang_Menus_Throwing_Fixture();
        $result = $fixture->run();

        $this->assertSame(array(), $result['items']);
        $this->assertSame(
            array('Menus could not be read. Nothing was changed, and the rest of the migration can continue.'),
            $result['errors']
        );
        $this->assertStringNotContainsString('boom', $result['errors'][0]);
    }

    public function test_database_fallback_nests_items_and_lists_empty_locations(): void
    {
        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $this->sample_database();
        try {
            $fixture = new Polylang_Menus_Db_Fixture();
            $fixture->stylesheet = 'flavor';
            $fixture->registered = array(
                'primary' => 'Primary',
                'footer' => 'Footer',
            );
            $fixture->options = array(
                'default_lang' => 'en',
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            );
            $result = $fixture->run();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('primary', 'footer'), $this->locations($result));
        $english = $this->location($result, 'primary')['translations']['en'];
        $this->assertSame(10, $english['menu_id']);
        $this->assertSame('Fish & Chips', $english['name']);
        $this->assertSame('fish-chips', $english['slug']);
        $this->assertSame('Home', $english['items'][0]['title']);
        $this->assertSame(array('home'), $english['items'][0]['classes']);
        $this->assertSame('_blank', $english['items'][0]['target']);
        $this->assertSame('nofollow', $english['items'][0]['xfn']);
        $this->assertSame('Welcome', $english['items'][0]['attr_title']);
        $this->assertSame('Front page', $english['items'][0]['description']);
        $this->assertSame('About us', $english['items'][0]['children'][0]['title']);
        $this->assertSame(42, $english['items'][0]['children'][0]['object_id']);
        $this->assertSame('page', $english['items'][0]['children'][0]['object']);
        $this->assertSame('News', $english['items'][0]['children'][1]['title']);
        $this->assertSame('category', $english['items'][0]['children'][1]['object']);
        $this->assertSame(7, $english['items'][0]['children'][1]['object_id']);
        $this->assertSame(11, $this->location($result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame('Hem', $this->location($result, 'primary')['translations']['sv']['items'][0]['title']);
        $this->assertSame(0, $this->location($result, 'footer')['translations']['en']['menu_id']);
        $this->assertSame(0, $this->location($result, 'footer')['translations']['sv']['menu_id']);
        $this->assertSame(0, $this->location($result, 'footer')['translations']['en']['link_count']);
        $this->assertSame(3, $english['link_count']);
        $this->assertFalse($english['items'][0]['switcher']);
        $this->assertSame('Primary', $this->location($result, 'primary')['label']);
        $this->assertSame('Footer', $this->location($result, 'footer')['label']);
    }

    public function test_menu_without_a_language_is_exported_as_unknown(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'registered' => array(
                'primary' => 'Primary',
            ),
            'theme_locations' => array(
                'primary' => 8,
            ),
            'menus' => array(
                8 => 'Plain',
            ),
            'slugs' => array(
                8 => 'plain',
            ),
        ));

        $result = $fixture->run();
        $again = $fixture->run();
        $primary = $this->location($result, 'primary');

        $this->assertSame($this->locations($result), $this->locations($again));
        $this->assertSame(array('unknown'), array_keys($primary['translations']));
        $this->assertSame(8, $primary['translations']['unknown']['menu_id']);
        $this->assertSame('Plain', $primary['translations']['unknown']['name']);
        $this->assertSame(array(), $result['errors']);
        $this->assertStringNotContainsString('unknown', implode("\n", $result['errors']));
        $counts = array_count_values($this->locations($again));
        $this->assertSame(1, $counts['primary']);
    }

    public function test_menu_without_a_language_uses_the_default_language(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'sv',
            'languages' => array('sv', 'en'),
            'registered' => array(
                'primary' => 'Primary',
            ),
            'menus' => array(
                8 => 'Plain',
                9 => 'Extra',
            ),
            'theme_locations' => array(
                'primary' => 8,
            ),
            'navigations' => array(
                array(
                    'id' => 40,
                    'name' => 'Sidhuvud',
                    'slug' => 'sidhuvud',
                    'language' => '',
                    'content' => '<!-- wp:navigation-link {"label":"Hem","url":"https://example.com/sv/"} /-->',
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $primary = $this->location($second, 'primary')['translations'];
        $extra = $this->location($second, '');
        $navigation = $this->location($second, 'navigation:sidhuvud')['translations'];
        $joined = implode("\n", $second['errors']);

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(8, $primary['sv']['menu_id']);
        $this->assertSame('Plain', $primary['sv']['name']);
        $this->assertArrayNotHasKey('unknown', $primary);
        $this->assertSame(9, $extra['translations']['sv']['menu_id']);
        $this->assertSame('Extra', $extra['translations']['sv']['name']);
        $this->assertSame(40, $navigation['sv']['menu_id']);
        $this->assertSame('Hem', $navigation['sv']['items'][0]['title']);
        $this->assertArrayNotHasKey('unknown', $navigation);
        $this->assertStringNotContainsString('no Polylang language', $joined);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
        $this->assertSame(1, array_count_values($this->locations($second))['navigation:sidhuvud']);
    }

    public function test_menu_without_a_language_is_kept_when_the_default_slot_is_taken(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en'),
            'registered' => array(
                'primary' => 'Primary',
            ),
            'menus' => array(
                10 => 'Main',
                8 => 'Plain',
            ),
            'term_languages' => array(
                10 => 'en',
            ),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Kontakt',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 4,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $plain = $this->location($second, '');

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(10, $this->location($second, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(8, $plain['translations']['en']['menu_id']);
        $this->assertSame('Plain', $plain['translations']['en']['name']);
        $this->assertSame('Kontakt', $plain['translations']['en']['items'][0]['title']);
        $this->assertSame('', $plain['translations']['en']['items'][0]['url']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_default_language_slug_for_an_unlabeled_menu_is_lowercase(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'SV',
            'languages' => array('SV', 'EN'),
            'registered' => array(
                'primary' => 'Primär',
            ),
            'menus' => array(
                8 => 'Förrätter',
                9 => 'Extra',
            ),
            'theme_locations' => array(
                'primary' => 8,
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Fågel',
                        'url' => 'https://example.com/öl',
                        'object' => 'custom',
                        'object_id' => 1,
                    ),
                ),
            ),
            'navigations' => array(
                array(
                    'id' => 40,
                    'name' => 'Sidhuvud',
                    'slug' => 'sidhuvud',
                    'language' => '',
                    'content' => '<!-- wp:navigation-link {"label":"Ärtor","url":"https://example.com/ärtor"} /-->',
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $primary = $this->location($second, 'primary')['translations'];
        $extra = $this->location($second, '');
        $navigation = $this->location($second, 'navigation:sidhuvud')['translations'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(array('sv', 'EN'), array_keys($primary));
        $this->assertArrayNotHasKey('SV', $primary);
        $this->assertSame(8, $primary['sv']['menu_id']);
        $this->assertSame('Förrätter', $primary['sv']['name']);
        $this->assertSame('Fågel', $primary['sv']['items'][0]['title']);
        $this->assertSame('https://example.com/öl', $primary['sv']['items'][0]['url']);
        $this->assertSame(9, $extra['translations']['sv']['menu_id']);
        $this->assertArrayNotHasKey('SV', $extra['translations']);
        $this->assertSame(40, $navigation['sv']['menu_id']);
        $this->assertSame('Ärtor', $navigation['sv']['items'][0]['title']);
        $this->assertSame('https://example.com/ärtor', $navigation['sv']['items'][0]['url']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, $counts['primary']);
        $this->assertSame(1, $counts['navigation:sidhuvud']);
    }

    public function test_hyphenated_default_language_slug_is_lowercase(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'default_language' => 'PT-BR',
            'languages' => array('PT-BR'),
            'menus' => array(3 => 'Cardápio'),
            'items' => array(
                3 => array(
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Pão',
                        'url' => '',
                        'object' => 'custom',
                        'object_id' => 1,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $menu = $this->location($second, '');

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(3, $menu['translations']['pt-br']['menu_id']);
        $this->assertArrayNotHasKey('PT-BR', $menu['translations']);
        $this->assertSame('Pão', $menu['translations']['pt-br']['items'][0]['title']);
        $this->assertSame('', $menu['translations']['pt-br']['items'][0]['url']);
        $this->assertSame(array(), $second['errors']);
    }

    public function test_child_row_stored_before_parent_stays_nested_under_sv(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'registered' => array(
                'primary' => 'Primär',
            ),
            'menus' => array(8 => 'Meny'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 30,
                        'parent' => 10,
                        'title' => 'Ärtor',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 4,
                        'position' => 1,
                    ),
                    array(
                        'id' => 31,
                        'parent' => 10,
                        'title' => 'Öl',
                        'url' => 'https://example.com/öl',
                        'object' => 'custom',
                        'object_id' => 5,
                        'position' => 2,
                    ),
                    array(
                        'id' => 10,
                        'parent' => 0,
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 3,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $items = $this->location($second, 'primary')['translations']['sv']['items'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame($first, $second);
        $this->assertCount(1, $items);
        $this->assertSame('Mat', $items[0]['title']);
        $this->assertSame('https://example.com/mat', $items[0]['url']);
        $this->assertCount(2, $items[0]['children']);
        $this->assertSame('Ärtor', $items[0]['children'][0]['title']);
        $this->assertSame('', $items[0]['children'][0]['url']);
        $this->assertSame(10, $items[0]['children'][0]['parent']);
        $this->assertSame('Öl', $items[0]['children'][1]['title']);
        $this->assertSame('https://example.com/öl', $items[0]['children'][1]['url']);
        $this->assertSame(10, $items[0]['children'][1]['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, $counts['primary']);
    }

    public function test_sv_menu_nests_by_parent_id_when_child_rows_come_first(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'menus' => array(8 => 'Meny'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 30,
                        'parent' => '10',
                        'title' => 'R&auml;ksm&ouml;rg&aring;s',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 4,
                        'position' => 1,
                    ),
                    array(
                        'id' => 31,
                        'parent' => '30',
                        'title' => 'F&aring;gel',
                        'url' => 'https://example.com/fågel',
                        'object' => 'custom',
                        'object_id' => 6,
                        'position' => 2,
                    ),
                    array(
                        'id' => 10,
                        'parent' => '0',
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 9,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $items = $this->location($second, 'primary')['translations']['sv']['items'];
        $titles = array();
        foreach ($items as $item) {
            $titles[] = $item['title'];
        }

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(array('Mat'), $titles);
        $this->assertSame('Räksmörgås', $items[0]['children'][0]['title']);
        $this->assertSame('', $items[0]['children'][0]['url']);
        $this->assertSame(10, $items[0]['children'][0]['parent']);
        $this->assertSame('Fågel', $items[0]['children'][0]['children'][0]['title']);
        $this->assertSame('https://example.com/fågel', $items[0]['children'][0]['children'][0]['url']);
        $this->assertSame(30, $items[0]['children'][0]['children'][0]['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_sv_title_decodes_swedish_entities_once_and_drops_null_bytes(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'menus' => array(8 => 'Meny'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 30,
                        'parent' => '10',
                        'title' => "R&amp;auml;k\0sm&ouml;rg&aring;s",
                        'url' => "https://example.com/r%C3%A4tt\0",
                        'object' => 'custom',
                        'object_id' => 4,
                        'position' => 2,
                    ),
                    array(
                        'id' => 31,
                        'parent' => '10',
                        'title' => "&#214;\0l",
                        'url' => "https://example.com/\0öl",
                        'object' => 'custom',
                        'object_id' => 5,
                        'position' => 3,
                    ),
                    array(
                        'id' => 10,
                        'parent' => '0',
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 9,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $items = $this->location($second, 'primary')['translations']['sv']['items'];

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame(array('Mat'), array($items[0]['title']));
        $this->assertSame('R&auml;ksmörgås', $items[0]['children'][0]['title']);
        $this->assertSame('https://example.com/r%C3%A4tt', $items[0]['children'][0]['url']);
        $this->assertSame(10, $items[0]['children'][0]['parent']);
        $this->assertStringNotContainsString("\0", $items[0]['children'][0]['title']);
        $this->assertStringNotContainsString("\0", $items[0]['children'][0]['url']);
        $this->assertSame('Öl', $items[0]['children'][1]['title']);
        $this->assertSame('https://example.com/öl', $items[0]['children'][1]['url']);
        $this->assertSame(10, $items[0]['children'][1]['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_sv_parent_id_with_a_null_byte_still_nests(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'menus' => array(8 => 'Meny'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => "30\0",
                        'parent' => "10\0",
                        'title' => "R&amp;auml;k\0sm&ouml;rg&aring;s",
                        'url' => "https://example.com/r%C3%A4tt\0",
                        'object' => 'custom',
                        'object_id' => 4,
                        'position' => "2\0",
                    ),
                    array(
                        'id' => 10,
                        'parent' => 0,
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 9,
                    ),
                ),
            ),
        ));

        $second = $fixture->export();
        $child = $this->location($second, 'primary')['translations']['sv']['items'][0]['children'][0];

        $this->assertSame('Mat', $this->location($second, 'primary')['translations']['sv']['items'][0]['title']);
        $this->assertSame(30, $child['id']);
        $this->assertSame(10, $child['parent']);
        $this->assertSame('R&auml;ksmörgås', $child['title']);
        $this->assertSame('https://example.com/r%C3%A4tt', $child['url']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_sv_siblings_follow_position_and_keep_swedish_classes(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'menus' => array(8 => 'R&auml;ksm&ouml;rg&aring;s'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 31,
                        'parent' => '10',
                        'title' => "Fågel\0",
                        'url' => 'https://example.com/fågel',
                        'object' => 'custom',
                        'object_id' => 6,
                        'position' => 8,
                        'classes' => array("sen\0", 'meny-fågel', 'meny-fågel'),
                    ),
                    array(
                        'id' => 30,
                        'parent' => '10',
                        'title' => 'R&auml;ksm&ouml;rg&aring;s',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 4,
                        'position' => 2,
                        'classes' => "meny-r&auml;tt current\0",
                    ),
                    array(
                        'id' => 10,
                        'parent' => '0',
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 9,
                    ),
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $translation = $this->location($second, 'primary')['translations']['sv'];
        $items = $translation['items'];

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame('Räksmörgås', $translation['name']);
        $this->assertSame(array('Mat'), array($items[0]['title']));
        $this->assertSame('Räksmörgås', $items[0]['children'][0]['title']);
        $this->assertSame(array('meny-rätt', 'current'), $items[0]['children'][0]['classes']);
        $this->assertSame(10, $items[0]['children'][0]['parent']);
        $this->assertSame('Fågel', $items[0]['children'][1]['title']);
        $this->assertSame('https://example.com/fågel', $items[0]['children'][1]['url']);
        $this->assertSame(array('sen', 'meny-fågel'), $items[0]['children'][1]['classes']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_sv_child_stored_first_keeps_swedish_text_and_drops_null_fields(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'menus' => array(8 => 'Meny'),
            'term_languages' => array(8 => 'sv'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('sv' => 8),
                    ),
                ),
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 30,
                        'parent' => '10',
                        'title' => 'R&auml;ksm&ouml;rg&aring;s',
                        'url' => "https://example.com/fågel\0",
                        'object' => "page\0",
                        'object_id' => 4,
                        'type' => "post_type\0",
                        'attr_title' => "F&ouml;nster\0",
                        'description' => "Sm&ouml;rg&aring;s\0",
                        'target' => "_blank\0",
                        'xfn' => "nofollow\0",
                        'position' => 1,
                    ),
                    array(
                        'id' => 10,
                        'parent' => 0,
                        'title' => 'Mat',
                        'url' => 'https://example.com/mat',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 4,
                    ),
                ),
            ),
        ));

        $second = $fixture->export();
        $child = $this->location($second, 'primary')['translations']['sv']['items'][0]['children'][0];

        $this->assertSame('Räksmörgås', $child['title']);
        $this->assertSame('https://example.com/fågel', $child['url']);
        $this->assertSame('page', $child['object']);
        $this->assertSame('post_type', $child['type']);
        $this->assertSame('Fönster', $child['attr_title']);
        $this->assertSame('Smörgås', $child['description']);
        $this->assertSame('_blank', $child['target']);
        $this->assertSame('nofollow', $child['xfn']);
        $this->assertSame(10, $child['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_database_child_row_before_parent_stays_nested_under_sv(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menus = array(
            array(
                'id' => 12,
                'name' => 'Meny',
                'slug' => 'meny',
            ),
        );
        $database->term_languages = array(
            12 => 'pll_sv',
        );
        $database->term_translations = array();
        $database->menu_posts = array(
            12 => array(
                array(
                    'id' => 31,
                    'title' => 'Öl',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 2,
                ),
                array(
                    'id' => 32,
                    'title' => 'Sm&ouml;rg&aring;s',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 0,
                ),
                array(
                    'id' => 30,
                    'title' => 'R&auml;ksm&ouml;rg&aring;s',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 1,
                ),
                array(
                    'id' => 10,
                    'title' => 'Mat',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 3,
                ),
            ),
        );
        $database->meta[32] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '0',
            '_menu_item_menu_item_parent' => '30',
            '_menu_item_url' => '',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[30] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'page',
            '_menu_item_object_id' => '4',
            '_menu_item_menu_item_parent' => '10',
            '_menu_item_url' => '',
            '_menu_item_target' => '',
            '_menu_item_classes' => serialize(array('meny-r&auml;tt', "current\0-item", 'meny-rätt', '')),
            '_menu_item_xfn' => '',
        );
        $database->meta[31] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '5',
            '_menu_item_menu_item_parent' => '10',
            '_menu_item_url' => 'https://example.com/öl',
            '_menu_item_target' => '',
            '_menu_item_classes' => "meny-öl\0 sen",
            '_menu_item_xfn' => '',
        );
        $database->meta[10] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '1',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => 'https://example.com/mat',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('sv' => 12),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $first = $menus->export();
            $second = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $items = $this->location($second, 'primary')['translations']['sv']['items'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertCount(1, $items);
        $this->assertSame('Mat', $items[0]['title']);
        $this->assertSame('https://example.com/mat', $items[0]['url']);
        $this->assertCount(2, $items[0]['children']);
        $this->assertSame('Räksmörgås', $items[0]['children'][0]['title']);
        $this->assertSame('', $items[0]['children'][0]['url']);
        $this->assertSame(10, $items[0]['children'][0]['parent']);
        $this->assertSame(array('meny-rätt', 'current-item'), $items[0]['children'][0]['classes']);
        $this->assertSame('Smörgås', $items[0]['children'][0]['children'][0]['title']);
        $this->assertSame('', $items[0]['children'][0]['children'][0]['url']);
        $this->assertSame(30, $items[0]['children'][0]['children'][0]['parent']);
        $this->assertSame('Öl', $items[0]['children'][1]['title']);
        $this->assertSame('https://example.com/öl', $items[0]['children'][1]['url']);
        $this->assertSame(array('meny-öl', 'sen'), $items[0]['children'][1]['classes']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, $counts['primary']);
    }

    public function test_database_sv_title_decodes_swedish_entities_once(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menus = array(
            array(
                'id' => 13,
                'name' => 'Meny',
                'slug' => 'meny',
            ),
        );
        $database->term_languages = array(
            13 => 'pll_sv',
        );
        $database->term_translations = array();
        $database->menu_posts = array(
            13 => array(
                array(
                    'id' => 41,
                    'title' => "R&amp;auml;k\0sm&ouml;rg&aring;s",
                    'attr_title' => '',
                    'description' => '',
                    'position' => 2,
                ),
                array(
                    'id' => 42,
                    'title' => "&#214;\0l",
                    'attr_title' => '',
                    'description' => '',
                    'position' => 3,
                ),
                array(
                    'id' => 40,
                    'title' => 'Mat',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 9,
                ),
            ),
        );
        $database->meta[41] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '4',
            '_menu_item_menu_item_parent' => '40',
            '_menu_item_url' => "https://example.com/r%C3%A4tt\0",
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[42] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '5',
            '_menu_item_menu_item_parent' => '40',
            '_menu_item_url' => "https://example.com/\0öl",
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[40] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '1',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => 'https://example.com/mat',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('sv' => 13),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $first = $menus->export();
            $second = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $items = $this->location($second, 'primary')['translations']['sv']['items'];

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame('Mat', $items[0]['title']);
        $this->assertSame('R&auml;ksmörgås', $items[0]['children'][0]['title']);
        $this->assertSame('https://example.com/r%C3%A4tt', $items[0]['children'][0]['url']);
        $this->assertSame(40, $items[0]['children'][0]['parent']);
        $this->assertStringNotContainsString("\0", $items[0]['children'][0]['title']);
        $this->assertStringNotContainsString("\0", $items[0]['children'][0]['url']);
        $this->assertSame('Öl', $items[0]['children'][1]['title']);
        $this->assertSame('https://example.com/öl', $items[0]['children'][1]['url']);
        $this->assertSame(40, $items[0]['children'][1]['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_nested_item_stays_under_its_parent_for_a_lowercased_default(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'default_language' => 'SV',
            'languages' => array('SV'),
            'registered' => array(
                'primary' => 'Primär',
            ),
            'menus' => array(8 => 'Förrätter'),
            'theme_locations' => array(
                'primary' => 8,
            ),
            'items' => array(
                8 => array(
                    array(
                        'id' => 3,
                        'parent' => 2,
                        'title' => 'Djup',
                        'url' => '',
                        'object' => 'custom',
                        'object_id' => 0,
                        'position' => 3,
                    ),
                    array(
                        'id' => 2,
                        'parent' => 1,
                        'title' => 'Kontakt',
                        'url' => '',
                        'object' => 'page',
                        'object_id' => 4,
                        'position' => 2,
                    ),
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Hem',
                        'url' => 'https://example.com/hem',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                ),
            ),
            'navigations' => array(
                array(
                    'id' => 40,
                    'name' => 'Sidhuvud',
                    'slug' => 'sidhuvud',
                    'language' => '',
                    'content' => '<!-- wp:navigation-submenu {"label":"Om","url":"https://example.com/om"} -->'
                        . '<!-- wp:navigation-link {"label":"Ärtor","url":""} /-->'
                        . '<!-- /wp:navigation-submenu -->',
                ),
            ),
        ));

        $first = $fixture->export();
        $second = $fixture->export();
        $link = $this->location($second, 'primary')['translations']['sv']['items'];
        $navigation = $this->location($second, 'navigation:sidhuvud')['translations']['sv']['items'];

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('SV', $this->location($second, 'primary')['translations']);
        $this->assertCount(1, $link);
        $this->assertSame('Hem', $link[0]['title']);
        $this->assertSame('https://example.com/hem', $link[0]['url']);
        $this->assertCount(1, $link[0]['children']);
        $this->assertSame('Kontakt', $link[0]['children'][0]['title']);
        $this->assertSame('', $link[0]['children'][0]['url']);
        $this->assertSame(1, $link[0]['children'][0]['parent']);
        $this->assertSame('Djup', $link[0]['children'][0]['children'][0]['title']);
        $this->assertSame('', $link[0]['children'][0]['children'][0]['url']);
        $this->assertSame(2, $link[0]['children'][0]['children'][0]['parent']);
        $this->assertSame(array(), $link[0]['children'][0]['children'][0]['children']);
        $this->assertCount(1, $navigation);
        $this->assertSame('Om', $navigation[0]['title']);
        $this->assertSame('Ärtor', $navigation[0]['children'][0]['title']);
        $this->assertSame('', $navigation[0]['children'][0]['url']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, array_count_values($this->locations($second))['primary']);
    }

    public function test_database_nested_item_stays_under_parent_for_lowercased_default(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->menus = array(
            array(
                'id' => 12,
                'name' => 'Förrätter',
                'slug' => 'forratter',
            ),
        );
        $database->term_languages = array();
        $database->term_translations = array();
        $database->menu_posts = array(
            12 => array(
                array(
                    'id' => 21,
                    'title' => 'Kontakt',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 2,
                ),
                array(
                    'id' => 20,
                    'title' => 'Hem',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 1,
                ),
            ),
        );
        $database->meta[21] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'page',
            '_menu_item_object_id' => '4',
            '_menu_item_menu_item_parent' => '20',
            '_menu_item_url' => '',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->meta[20] = array(
            '_menu_item_type' => 'custom',
            '_menu_item_object' => 'custom',
            '_menu_item_object_id' => '1',
            '_menu_item_menu_item_parent' => '0',
            '_menu_item_url' => 'https://example.com/hem',
            '_menu_item_target' => '',
            '_menu_item_classes' => '',
            '_menu_item_xfn' => '',
        );
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'SV',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => 12,
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $menus = new Polylang_Menus();
            $first = $menus->export();
            $second = $menus->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $translations = $this->location($second, 'primary')['translations'];
        $link = $translations['sv']['items'];
        $counts = array_count_values($this->locations($second));

        $this->assertSame($this->locations($first), $this->locations($second));
        $this->assertArrayNotHasKey('SV', $translations);
        $this->assertCount(1, $link);
        $this->assertSame('Hem', $link[0]['title']);
        $this->assertSame('Kontakt', $link[0]['children'][0]['title']);
        $this->assertSame('', $link[0]['children'][0]['url']);
        $this->assertSame(20, $link[0]['children'][0]['parent']);
        $this->assertSame(array(), $second['errors']);
        $this->assertSame(1, $counts['primary']);
    }

    public function test_option_table_is_read_when_wordpress_functions_are_missing(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('en' => 10),
                ),
            ),
        ));

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $result = (new Polylang_Menus())->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array('primary'), $this->locations($result));
        $this->assertSame('About us', $this->location($result, 'primary')['translations']['en']['items'][0]['children'][0]['title']);
        $this->assertSame(11, $this->location($result, 'primary')['translations']['sv']['menu_id']);

        $database->skipped = array(10 => 2);
        $GLOBALS['wpdb'] = $database;
        try {
            $with_drafts = (new Polylang_Menus())->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $this->assertContains(
            'The "Fish & Chips" menu (en) has 2 links that are not published. Those links were left out of the export.',
            $with_drafts['errors']
        );
        $this->assertSame(
            'Home',
            $this->location($with_drafts, 'primary')['translations']['en']['items'][0]['title']
        );
    }

    public function test_block_navigation_is_nested_by_language(): void
    {
        $english = <<<'HTML'
<!-- wp:navigation-link {"label":"Home","type":"page","id":2,"url":"https://example.com/","kind":"post-type","opensInNewTab":true,"rel":"nofollow","className":"featured external"} /-->
<!-- wp:navigation-submenu {"label":"About","type":"page","id":3,"url":"https://example.com/about/","kind":"post-type"} -->
<!-- wp:navigation-link {"label":"Team","type":"page","id":4,"url":"https://example.com/team/","kind":"post-type"} /-->
<!-- /wp:navigation-submenu -->
<!-- wp:polylang/language-switcher {"label":""} /-->
HTML;
        $swedish = <<<'HTML'
<!-- wp:navigation-link {"label":"Hem","url":"https://example.com/sv/","kind":"custom"} /-->
HTML;

        $fixture = $this->fixture(array(
            'active' => true,
            'default_language' => 'en',
            'languages' => array('en', 'sv'),
            'navigations' => array(
                array(
                    'id' => 20,
                    'name' => 'Header',
                    'slug' => 'header',
                    'language' => 'en',
                    'translations' => array('en' => 20, 'sv' => 21),
                    'content' => $english,
                ),
                array(
                    'id' => 21,
                    'name' => 'Sidhuvud',
                    'slug' => 'sidhuvud',
                    'language' => 'sv',
                    'translations' => array('en' => 20, 'sv' => 21),
                    'content' => $swedish,
                ),
            ),
        ));

        $result = $fixture->run();
        $navigation = $this->location($result, 'navigation:header');

        $this->assertSame('Header', $navigation['label']);
        $this->assertSame(array('en', 'sv'), array_keys($navigation['translations']));
        $this->assertSame(20, $navigation['translations']['en']['menu_id']);
        $this->assertSame('navigation', $navigation['translations']['en']['kind']);
        $this->assertSame(4, $navigation['translations']['en']['link_count']);
        $this->assertSame('Home', $navigation['translations']['en']['items'][0]['title']);
        $this->assertSame(2, $navigation['translations']['en']['items'][0]['object_id']);
        $this->assertSame('page', $navigation['translations']['en']['items'][0]['object']);
        $this->assertSame('_blank', $navigation['translations']['en']['items'][0]['target']);
        $this->assertSame('nofollow', $navigation['translations']['en']['items'][0]['xfn']);
        $this->assertSame(array('featured', 'external'), $navigation['translations']['en']['items'][0]['classes']);
        $this->assertSame('About', $navigation['translations']['en']['items'][1]['title']);
        $this->assertSame('Team', $navigation['translations']['en']['items'][1]['children'][0]['title']);
        $this->assertSame(4, $navigation['translations']['en']['items'][1]['children'][0]['object_id']);
        $this->assertSame($navigation['translations']['en']['items'][1]['id'], $navigation['translations']['en']['items'][1]['children'][0]['parent']);
        $this->assertTrue($navigation['translations']['en']['items'][2]['switcher']);
        $this->assertSame('Languages', $navigation['translations']['en']['items'][2]['title']);
        $this->assertSame('#pll_switcher', $navigation['translations']['en']['items'][2]['url']);
        $this->assertSame('Hem', $navigation['translations']['sv']['items'][0]['title']);
        $this->assertSame(1, $navigation['translations']['sv']['link_count']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_navigation_posts_are_read_from_the_database(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
        ));
        $database->navigation_posts = array(
            array(
                'id' => 30,
                'name' => 'Header',
                'slug' => 'header',
                'content' => '<!-- wp:navigation-link {"label":"Home","type":"page","id":2,"url":"https://example.com/"} /-->',
            ),
        );

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $result = (new Polylang_Menus())->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $navigation = $this->location($result, 'navigation:header');
        $this->assertSame('Header', $navigation['label']);
        $this->assertSame(30, $navigation['translations']['en']['menu_id']);
        $this->assertSame('navigation', $navigation['translations']['en']['kind']);
        $this->assertSame('Home', $navigation['translations']['en']['items'][0]['title']);
        $this->assertSame(2, $navigation['translations']['en']['items'][0]['object_id']);
    }

    public function test_bad_rows_are_skipped_instead_of_throwing(): void
    {
        $fixture = $this->fixture(array(
            'active' => true,
            'stylesheet' => 'flavor',
            'languages' => array('en'),
            'menus' => array(10 => 'Main'),
            'options' => array(
                'nav_menus' => array(
                    'flavor' => array(
                        'primary' => array('en' => 10),
                    ),
                ),
            ),
            'items' => array(
                10 => array(
                    'nope',
                    null,
                    12,
                    array(
                        'id' => 1,
                        'parent' => 0,
                        'title' => 'Home',
                        'url' => '/',
                        'object' => 'custom',
                        'object_id' => 1,
                        'position' => 1,
                    ),
                ),
            ),
        ));

        $result = $fixture->run();
        $english = $this->location($result, 'primary')['translations']['en'];

        $this->assertSame(array(), $result['errors']);
        $this->assertCount(1, $english['items']);
        $this->assertSame('Home', $english['items'][0]['title']);
        $this->assertSame(1, $english['link_count']);
    }

    public function test_corrupt_translation_description_does_not_throw(): void
    {
        if (function_exists('get_option')) {
            $this->markTestSkipped('WordPress is loaded.');
        }

        $database = $this->sample_database();
        $database->option_values['stylesheet'] = 'flavor';
        $database->option_values['polylang'] = serialize(array(
            'default_lang' => 'en',
            'nav_menus' => array(
                'flavor' => array(
                    'primary' => array('en' => 10),
                ),
            ),
        ));
        $database->term_translations = array(
            10 => 'a:1:{broken',
            11 => 'a:1:{broken',
        );

        $previous = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        $GLOBALS['wpdb'] = $database;
        try {
            $result = (new Polylang_Menus())->export();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }

        $this->assertSame(10, $this->location($result, 'primary')['translations']['en']['menu_id']);
        $this->assertSame(0, $this->location($result, 'primary')['translations']['sv']['menu_id']);
        $this->assertSame('Home', $this->location($result, 'primary')['translations']['en']['items'][0]['title']);
    }

    public function test_source_does_not_write_or_delete_polylang_data(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/tolka/class-polylang-menus.php');
        $this->assertIsString($source);
        $forbidden = array(
            'update_option',
            'delete_option',
            'wp_delete_',
            'wp_update_',
            'wp_insert_',
            'update_post_meta',
            'update_term_meta',
            'wp_update_nav_menu',
        );
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    /**
     * @param array<string, mixed> $values Fixture values.
     * @return Polylang_Menus_Fixture
     */
    private function fixture(array $values)
    {
        $fixture = new Polylang_Menus_Fixture();
        foreach ($values as $name => $value) {
            $fixture->$name = $value;
        }
        return $fixture;
    }

    /**
     * @param array<string, mixed> $result Export envelope.
     * @return string[]
     */
    private function locations(array $result)
    {
        $locations = array();
        foreach ($result['items'] as $item) {
            $locations[] = $item['location'];
        }
        return $locations;
    }

    /**
     * @param array<string, mixed> $result   Export envelope.
     * @param string               $location Location slug.
     * @return array
     */
    private function location(array $result, $location)
    {
        foreach ($result['items'] as $item) {
            if ($item['location'] === $location) {
                return $item;
            }
        }
        $this->fail('Location was not exported: ' . $location);
    }

    /**
     * @param array<string, mixed> $result Export envelope.
     * @return array<int, bool>
     */
    private function menu_ids(array $result)
    {
        $ids = array();
        foreach ($result['items'] as $item) {
            foreach ($item['translations'] as $translation) {
                $ids[(int) $translation['menu_id']] = true;
            }
        }
        return $ids;
    }

    /**
     * @param array<int, array> $items  Nested items.
     * @param string            $title  Title to count.
     * @return int
     */
    private function count_titles(array $items, $title)
    {
        $count = 0;
        foreach ($items as $item) {
            if ($item['title'] === $title) {
                $count++;
            }
            if (! empty($item['children'])) {
                $count += $this->count_titles($item['children'], $title);
            }
        }
        return $count;
    }

    /**
     * @return Tolka_Menus_Fake_Wpdb
     */
    private function sample_database()
    {
        $database = new Tolka_Menus_Fake_Wpdb();
        $database->languages = array(
            array('slug' => 'en'),
            array('slug' => 'sv'),
        );
        $database->menus = array(
            array('id' => 10, 'name' => 'Fish &amp; Chips', 'slug' => 'fish-chips'),
            array('id' => 11, 'name' => 'Fisk', 'slug' => 'fisk'),
        );
        $database->term_languages = array(
            10 => 'pll_en',
            11 => 'pll_sv',
        );
        $database->term_translations = array(
            10 => serialize(array('en' => 10, 'sv' => 11)),
            11 => serialize(array('en' => 10, 'sv' => 11)),
        );
        $database->menu_posts = array(
            10 => array(
                array(
                    'id' => 1,
                    'title' => 'Home',
                    'attr_title' => 'Welcome',
                    'description' => 'Front page',
                    'position' => 1,
                ),
                array(
                    'id' => 2,
                    'title' => '',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 2,
                ),
                array(
                    'id' => 5,
                    'title' => '',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 3,
                ),
            ),
            11 => array(
                array(
                    'id' => 3,
                    'title' => 'Hem',
                    'attr_title' => '',
                    'description' => '',
                    'position' => 1,
                ),
            ),
        );
        $database->meta = array(
            1 => array(
                '_menu_item_type' => 'custom',
                '_menu_item_object' => 'custom',
                '_menu_item_object_id' => '1',
                '_menu_item_menu_item_parent' => '0',
                '_menu_item_url' => 'https://example.com/',
                '_menu_item_target' => '_blank',
                '_menu_item_classes' => serialize(array('home', '')),
                '_menu_item_xfn' => 'nofollow',
            ),
            2 => array(
                '_menu_item_type' => 'post_type',
                '_menu_item_object' => 'page',
                '_menu_item_object_id' => '42',
                '_menu_item_menu_item_parent' => '1',
                '_menu_item_url' => '',
                '_menu_item_target' => '',
                '_menu_item_classes' => '',
                '_menu_item_xfn' => '',
            ),
            5 => array(
                '_menu_item_type' => 'taxonomy',
                '_menu_item_object' => 'category',
                '_menu_item_object_id' => '7',
                '_menu_item_menu_item_parent' => '1',
                '_menu_item_url' => '',
                '_menu_item_target' => '',
                '_menu_item_classes' => '',
                '_menu_item_xfn' => '',
            ),
            3 => array(
                '_menu_item_type' => 'custom',
                '_menu_item_object' => 'custom',
                '_menu_item_object_id' => '3',
                '_menu_item_menu_item_parent' => '0',
                '_menu_item_url' => 'https://example.com/sv/',
                '_menu_item_target' => '',
                '_menu_item_classes' => '',
                '_menu_item_xfn' => '',
            ),
        );
        $database->post_titles = array(
            42 => 'About us',
        );
        $database->term_names = array(
            7 => 'News',
        );
        return $database;
    }
}

/**
 * In-memory Polylang menus.
 */
class Polylang_Menus_Fixture extends Polylang_Menus
{
    /**
     * @var bool
     */
    public $wordpress = true;

    /**
     * @var bool
     */
    public $active = false;

    /**
     * @var array
     */
    public $options = array();

    /**
     * @var string
     */
    public $stylesheet = '';

    /**
     * @var array<string, string>
     */
    public $registered = array();

    /**
     * @var array<string, mixed>
     */
    public $theme_locations = array();

    /**
     * @var array<int, string>
     */
    public $menus = array();

    /**
     * @var array<int, string>
     */
    public $slugs = array();

    /**
     * @var string[]
     */
    public $languages = array();

    /**
     * @var string
     */
    public $default_language = '';

    /**
     * @var array<int, string>
     */
    public $term_languages = array();

    /**
     * @var array<int, array<string, int>>
     */
    public $term_translations = array();

    /**
     * @var array<int, array<int, mixed>>
     */
    public $items = array();

    /**
     * @var array<int, int>
     */
    public $skipped = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    public $navigations = array();

    /**
     * @return array{items: array, errors: string[]}
     */
    public function run()
    {
        return $this->export();
    }

    /**
     * @return bool
     */
    protected function wordpress_loaded()
    {
        return (bool) $this->wordpress;
    }

    /**
     * @return bool
     */
    protected function polylang_active()
    {
        return (bool) $this->active;
    }

    /**
     * @return array
     */
    protected function read_polylang_options()
    {
        return is_array($this->options) ? $this->options : array();
    }

    /**
     * @return string
     */
    protected function read_stylesheet()
    {
        return (string) $this->stylesheet;
    }

    /**
     * @return array<string, string>
     */
    protected function read_registered_locations()
    {
        return $this->registered;
    }

    /**
     * @return array<string, mixed>
     */
    protected function read_theme_menu_locations()
    {
        return $this->theme_locations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function read_nav_menus()
    {
        $menus = array();
        foreach ($this->menus as $id => $name) {
            $menus[] = array(
                'id' => (int) $id,
                'name' => $name,
                'slug' => isset($this->slugs[$id]) ? (string) $this->slugs[$id] : '',
            );
        }
        return $menus;
    }

    /**
     * @return string[]
     */
    protected function read_language_slugs()
    {
        return $this->languages;
    }

    /**
     * @return string
     */
    protected function read_default_language()
    {
        return (string) $this->default_language;
    }

    /**
     * @param int $term_id Menu term id.
     * @return string
     */
    protected function read_term_language($term_id)
    {
        $term_id = (int) $term_id;
        if (isset($this->term_languages[$term_id])) {
            return (string) $this->term_languages[$term_id];
        }
        return '';
    }

    /**
     * @param int $term_id Menu term id.
     * @return array<string, int>
     */
    protected function read_term_translations($term_id)
    {
        $term_id = (int) $term_id;
        if (isset($this->term_translations[$term_id]) && is_array($this->term_translations[$term_id])) {
            return $this->term_translations[$term_id];
        }
        return array();
    }

    /**
     * @param int $menu_id Menu term id.
     * @return array<int, mixed>
     */
    protected function read_menu_items($menu_id)
    {
        $menu_id = (int) $menu_id;
        if (! isset($this->items[$menu_id]) || ! is_array($this->items[$menu_id])) {
            return array();
        }
        return $this->items[$menu_id];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function read_navigation_posts()
    {
        return $this->navigations;
    }

    /**
     * @param int $menu_id Menu term id.
     * @return int
     */
    protected function read_skipped_item_count($menu_id)
    {
        $menu_id = (int) $menu_id;
        if (isset($this->skipped[$menu_id])) {
            return (int) $this->skipped[$menu_id];
        }
        return 0;
    }

    /**
     * @param int $menu_id Menu term id.
     * @return string|null
     */
    protected function read_menu_name($menu_id)
    {
        $menu_id = (int) $menu_id;
        if (array_key_exists($menu_id, $this->menus)) {
            return (string) $this->menus[$menu_id];
        }
        return null;
    }
}

/**
 * Forces the read path to fail.
 */
class Polylang_Menus_Throwing_Fixture extends Polylang_Menus_Fixture
{
    /**
     * @return array<string, string>
     */
    protected function read_registered_locations()
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * Uses the real readers, with theme and Polylang data supplied directly.
 */
class Polylang_Menus_Db_Fixture extends Polylang_Menus
{
    /**
     * @var array
     */
    public $options = array();

    /**
     * @var string
     */
    public $stylesheet = '';

    /**
     * @var array<string, string>
     */
    public $registered = array();

    /**
     * @return array{items: array, errors: string[]}
     */
    public function run()
    {
        return $this->export();
    }

    /**
     * @return bool
     */
    protected function wordpress_loaded()
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function polylang_active()
    {
        return false;
    }

    /**
     * @return array
     */
    protected function read_polylang_options()
    {
        return $this->options;
    }

    /**
     * @return string
     */
    protected function read_stylesheet()
    {
        return $this->stylesheet;
    }

    /**
     * @return array<string, string>
     */
    protected function read_registered_locations()
    {
        return $this->registered;
    }

    /**
     * @return array<string, mixed>
     */
    protected function read_theme_menu_locations()
    {
        return array();
    }
}

/**
 * Just enough of wpdb for the menu queries.
 */
class Tolka_Menus_Fake_Wpdb
{
    /**
     * @var string
     */
    public $posts = 'wp_posts';

    /**
     * @var string
     */
    public $postmeta = 'wp_postmeta';

    /**
     * @var string
     */
    public $terms = 'wp_terms';

    /**
     * @var string
     */
    public $term_taxonomy = 'wp_term_taxonomy';

    /**
     * @var string
     */
    public $term_relationships = 'wp_term_relationships';

    /**
     * @var string
     */
    public $options = 'wp_options';

    /**
     * @var string[]
     */
    public $queries = array();

    /**
     * @var array<string, string>
     */
    public $option_values = array();

    /**
     * @var array<int, array<string, string>>
     */
    public $languages = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    public $menus = array();

    /**
     * @var array<int, string>
     */
    public $term_languages = array();

    /**
     * @var array<int, string>
     */
    public $term_translations = array();

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    public $menu_posts = array();

    /**
     * @var array<int, array<string, string>>
     */
    public $meta = array();

    /**
     * @var array<int, string>
     */
    public $post_titles = array();

    /**
     * @var array<int, string>
     */
    public $post_languages = array();

    /**
     * @var array<int, string>
     */
    public $term_names = array();

    /**
     * @var array<int, int>
     */
    public $skipped = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    public $navigation_posts = array();

    /**
     * @param string $query Query with placeholders.
     * @param mixed  ...$args Placeholder values.
     * @return string
     */
    public function prepare($query, ...$args)
    {
        $index = 0;
        $built = preg_replace_callback(
            '/(?<!%)%[dfs]/',
            function ($match) use (&$index, $args) {
                if (! array_key_exists($index, $args)) {
                    return $match[0];
                }
                $value = $args[$index];
                $index++;
                if ($match[0] === '%d' || $match[0] === '%f') {
                    return (string) (0 + $value);
                }
                return "'" . str_replace("'", "''", (string) $value) . "'";
            },
            $query
        );
        return is_string($built) ? $built : $query;
    }

    /**
     * @param string     $query  Prepared query.
     * @param string|null $output Unused output mode.
     * @return array<int, array<string, mixed>>
     */
    public function get_results($query, $output = null)
    {
        unset($output);
        $this->queries[] = $query;

        if (strpos($query, "post_type = 'wp_navigation'") !== false) {
            return $this->navigation_posts;
        }
        if (strpos($query, 'COUNT(*)') !== false) {
            $menu_id = $this->number_after($query, 'tt.term_id = ');
            $total = isset($this->skipped[$menu_id]) ? (int) $this->skipped[$menu_id] : 0;
            return array(array('total' => (string) $total));
        }
        if (strpos($query, 'nav_menu_item') !== false) {
            $menu_id = $this->number_after($query, 'tt.term_id = ');
            return isset($this->menu_posts[$menu_id]) ? $this->menu_posts[$menu_id] : array();
        }
        if (strpos($query, 'postmeta') !== false) {
            $rows = array();
            foreach ($this->in_list($query, 'post_id IN (') as $id) {
                if (empty($this->meta[$id])) {
                    continue;
                }
                foreach ($this->meta[$id] as $key => $value) {
                    $rows[] = array(
                        'post_id' => $id,
                        'meta_key' => $key,
                        'meta_value' => $value,
                    );
                }
            }
            return $rows;
        }
        if (strpos($query, 'term_language') !== false) {
            $object_id = $this->number_after($query, 'tr.object_id = ');
            if (! isset($this->term_languages[$object_id])) {
                return array();
            }
            return array(array(
                'term_id' => 1000 + $object_id,
                'slug' => $this->term_languages[$object_id],
                'name' => $this->term_languages[$object_id],
                'description' => '',
            ));
        }
        if (strpos($query, 'term_translations') !== false) {
            $object_id = $this->number_after($query, 'tr.object_id = ');
            if (! isset($this->term_translations[$object_id])) {
                return array();
            }
            return array(array(
                'term_id' => 2000 + $object_id,
                'slug' => 'pll_' . $object_id,
                'name' => 'group',
                'description' => $this->term_translations[$object_id],
            ));
        }
        if (strpos($query, 'tr.object_id = ') !== false && strpos($query, "taxonomy = 'language'") !== false) {
            $object_id = $this->number_after($query, 'tr.object_id = ');
            if (! isset($this->post_languages[$object_id])) {
                return array();
            }
            return array(array(
                'term_id' => 3000 + $object_id,
                'slug' => $this->post_languages[$object_id],
                'name' => $this->post_languages[$object_id],
                'description' => '',
            ));
        }
        if (strpos($query, "taxonomy = 'language'") !== false) {
            return $this->languages;
        }
        if (strpos($query, 'option_name = ') !== false) {
            if (preg_match("/option_name = '([^']+)'/", $query, $matches) && isset($this->option_values[$matches[1]])) {
                return array(array('option_value' => $this->option_values[$matches[1]]));
            }
            return array();
        }
        if (strpos($query, "taxonomy = 'nav_menu'") !== false && preg_match('/WHERE t\.term_id = (\d+)/', $query, $menu_id_match)) {
            $term_id = (int) $menu_id_match[1];
            foreach ($this->menus as $menu) {
                if ((int) $menu['id'] === $term_id) {
                    return array(array('name' => $menu['name']));
                }
            }
            return array();
        }
        if (strpos($query, "taxonomy = 'nav_menu'") !== false) {
            return $this->menus;
        }
        if (strpos($query, 'post_title AS title') !== false) {
            $id = $this->number_after($query, 'ID = ');
            if (! isset($this->post_titles[$id])) {
                return array();
            }
            return array(array('title' => $this->post_titles[$id]));
        }
        if (strpos($query, 't.name AS name') !== false) {
            $id = $this->number_after($query, 't.term_id = ');
            if (! isset($this->term_names[$id])) {
                return array();
            }
            return array(array('name' => $this->term_names[$id]));
        }

        return array();
    }

    /**
     * @param string $query  Query.
     * @param string $needle Text before the integer.
     * @return int
     */
    private function number_after($query, $needle)
    {
        $pos = strpos($query, $needle);
        if ($pos === false) {
            return 0;
        }
        $slice = substr($query, $pos + strlen($needle));
        if (preg_match('/^\d+/', $slice, $matches)) {
            return (int) $matches[0];
        }
        return 0;
    }

    /**
     * @param string $query  Query.
     * @param string $needle Text before an IN list.
     * @return int[]
     */
    private function in_list($query, $needle)
    {
        $pos = strpos($query, $needle);
        if ($pos === false) {
            return array();
        }
        $slice = substr($query, $pos + strlen($needle));
        $end = strpos($slice, ')');
        if ($end === false) {
            return array();
        }
        $ids = array();
        foreach (explode(',', substr($slice, 0, $end)) as $part) {
            $ids[] = (int) trim($part);
        }
        return $ids;
    }
}
