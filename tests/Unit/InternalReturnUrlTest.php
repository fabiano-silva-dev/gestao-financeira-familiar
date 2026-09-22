<?php

namespace Tests\Unit;

use App\Support\InternalReturnUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class InternalReturnUrlTest extends TestCase
{
    public function test_it_keeps_a_reconciliation_path_with_filters_and_position(): void
    {
        $returnTo = InternalReturnUrl::fromRequest(
            Request::create('/regras/nova', 'GET', [
                'return_to' => '/conciliacao?account=12&period=2026-06&view=pending&focus=statement-9',
            ]),
            'reconciliation.index',
        );

        $this->assertSame(
            '/conciliacao?account=12&period=2026-06&view=pending&focus=statement-9',
            $returnTo,
        );
    }

    public function test_it_rejects_external_and_unrelated_paths(): void
    {
        $this->assertNull(InternalReturnUrl::fromRequest(
            Request::create('/regras/nova', 'GET', [
                'return_to' => 'https://evil.test/phish',
            ]),
            'reconciliation.index',
        ));
        $this->assertNull(InternalReturnUrl::fromRequest(
            Request::create('/regras/nova', 'GET', [
                'return_to' => '//evil.test/phish',
            ]),
            'reconciliation.index',
        ));
        $this->assertNull(InternalReturnUrl::fromRequest(
            Request::create('/regras/nova', 'GET', [
                'return_to' => '/regras',
            ]),
            'reconciliation.index',
        ));
        $this->assertNull(InternalReturnUrl::fromRequest(
            Request::create('/regras/nova', 'GET', [
                'return_to' => '',
            ]),
            'reconciliation.index',
        ));
    }
}
