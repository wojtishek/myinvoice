<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\FakturoidClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: FakturoidClient::getAll() musí projít VŠECHNY stránky kolekce.
 *
 * Dřív se stop-signál odvozoval výhradně z Link hlavičky (`next_page !== null`).
 * Fakturoid v3 ji ale nemá v dokumentaci a fakticky stránkuje přes `page` +
 * fixní 40 záznamů na stránku. Když Link hlavička nepřišla, import se tiše
 * zastavil po 1. stránce (40 položek). Tyto testy ověřují, že paginace běží
 * na PLNOST stránky a Link hlavička je jen doplňkový signál.
 *
 * Bez DB i HTTP: částečný mock metody get() (dovoluje dg/bypass-finals
 * z tests/bootstrap.php) cílí přesně na smyčku v getAll().
 */
#[Group('unit')]
final class FakturoidClientPaginationTest extends TestCase
{
    public function testWalksAllPagesWithoutAnyLinkHeader(): void
    {
        // 3 stránky, žádná Link hlavička (next_page === null všude) — přesně scénář
        // kvůli kterému se import dřív uřízl po prvních 40 položkách.
        $client = $this->clientReturning([
            $this->page(40),
            $this->page(40),
            $this->page(5),
        ], $requestedPages);

        $items = iterator_to_array($client->getAll(1, 'invoices.json'), false);

        self::assertCount(85, $items, 'Musí projít všechny 3 stránky i bez Link hlavičky');
        self::assertSame([1, 2, 3], $requestedPages, 'Postupně page=1,2,3');
    }

    public function testStopsOnEmptyPageWhenTotalIsExactMultipleOfPageSize(): void
    {
        // Přesně 80 záznamů = 2 plné stránky; 3. stránka přijde prázdná → stop.
        $client = $this->clientReturning([
            $this->page(40),
            $this->page(40),
            $this->page(0),
        ], $requestedPages);

        $items = iterator_to_array($client->getAll(1, 'subjects.json'), false);

        self::assertCount(80, $items);
        self::assertSame([1, 2, 3], $requestedPages, 'Prázdná 3. stránka smyčku ukončí (žádné nekonečno)');
    }

    public function testSinglePartialPageMakesExactlyOneRequest(): void
    {
        $client = $this->clientReturning([$this->page(7)], $requestedPages);

        $items = iterator_to_array($client->getAll(1, 'expenses.json'), false);

        self::assertCount(7, $items);
        self::assertSame([1], $requestedPages, 'Neúplná 1. stránka = jediný request');
    }

    public function testFollowsLinkHeaderEvenOnShortPage(): void
    {
        // Doplňkový signál: krátká stránka s "next" odkazem se přesto následuje.
        $client = $this->clientReturning([
            $this->page(3, nextPage: 'https://app.fakturoid.cz/api/v3/accounts/x/invoices.json?page=2'),
            $this->page(2),
        ], $requestedPages);

        $items = iterator_to_array($client->getAll(1, 'invoices.json'), false);

        self::assertCount(5, $items);
        self::assertSame([1, 2], $requestedPages);
    }

    // ── Helpers ──

    /**
     * Vyrobí FakturoidClient s mockovanou get() metodou vracející předané stránky.
     * getAll() zůstává reálné. $requestedPages dostane seznam page čísel, o která
     * smyčka požádala (kontrola počtu requestů + ochrana proti nekonečnu).
     *
     * @param list<array{items:list<array<string,mixed>>, next_page:?string}> $pages
     * @param list<int>|null $requestedPages out param
     */
    private function clientReturning(array $pages, ?array &$requestedPages = null): FakturoidClient
    {
        $requestedPages = [];
        $queue = $pages;

        $client = $this->getMockBuilder(FakturoidClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $client->expects($this->atLeastOnce())->method('get')->willReturnCallback(
            function (int $supplierId, string $endpoint, int $page = 1, array $extraQuery = []) use (&$queue, &$requestedPages) {
                $requestedPages[] = $page;
                self::assertNotEmpty(
                    $queue,
                    "get() požádal o page {$page} nad rámec připravených stránek — možná nekonečná smyčka",
                );
                return array_shift($queue);
            }
        );

        return $client;
    }

    /**
     * @return array{items:list<array<string,mixed>>, next_page:?string}
     */
    private function page(int $count, ?string $nextPage = null): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['id' => $i + 1];
        }
        return ['items' => $items, 'next_page' => $nextPage];
    }
}
