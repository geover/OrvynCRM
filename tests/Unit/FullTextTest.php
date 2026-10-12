<?php

namespace Tests\Unit;

use App\Support\FullText;
use Tests\TestCase;

class FullTextTest extends TestCase
{
    public function test_every_word_becomes_a_required_prefix_match(): void
    {
        $this->assertSame('+jane* +doe*', FullText::booleanQuery('Jane Doe', enabled: true));
        $this->assertSame('+bulkdea*', FullText::booleanQuery('Bulkdea', enabled: true));
    }

    public function test_boolean_mode_operators_in_user_input_never_reach_the_query(): void
    {
        // "@" is the proximity operator in InnoDB boolean mode, "-" excludes, quotes group: all are stripped
        $this->assertSame('+john* +example* +com*', FullText::booleanQuery('john@example.com', enabled: true));
        $this->assertSame('+foo* +bar*', FullText::booleanQuery('-foo "bar" +', enabled: true));
        $this->assertSame('+ünïcode*', FullText::booleanQuery('ünïcode', enabled: true));
    }

    public function test_terms_the_index_cannot_serve_return_null_so_callers_fall_back_to_like(): void
    {
        $this->assertNull(FullText::booleanQuery('jo smith', enabled: true), 'a word shorter than innodb_ft_min_token_size is ignored by the index');
        $this->assertNull(FullText::booleanQuery('   ', enabled: true));
        $this->assertNull(FullText::booleanQuery('---', enabled: true));
        $this->assertNull(FullText::booleanQuery('Jane Doe', enabled: false), 'SQLite has no FULLTEXT');
    }

    public function test_the_minimum_word_length_follows_the_server_setting(): void
    {
        config(['app.fulltext_min_word' => 2]);

        $this->assertSame('+jo* +smith*', FullText::booleanQuery('jo smith', enabled: true));
    }
}
