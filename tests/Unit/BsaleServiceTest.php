<?php

namespace Tests\Unit;

use App\Services\BsaleService;
use App\Services\Integrations\BsaleClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class BsaleServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_applies_filtered_pagination_for_the_last_partial_page(): void
    {
        $client = Mockery::mock(BsaleClient::class);
        $service = new BsaleService($client);

        $filters = [
            'emissiondaterange' => '[1740787200,1743465599]',
            'meta' => [
                'source' => 'month',
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-31',
            ],
        ];

        $client->shouldReceive('get')
            ->once()
            ->with('documents', Mockery::on(function (array $params): bool {
                return $params['state'] === 0
                    && $params['limit'] === 1
                    && $params['emissiondaterange'] === '[1740787200,1743465599]';
            }))
            ->andReturn($this->jsonResponse(['count' => 390]));

        $client->shouldReceive('get')
            ->once()
            ->with('documents', Mockery::on(function (array $params): bool {
                return $params['state'] === 0
                    && $params['limit'] === 40
                    && $params['offset'] === 0
                    && $params['emissiondaterange'] === '[1740787200,1743465599]'
                    && $params['expand'] === '[client,sellers,attributes,payments,details]';
            }))
            ->andReturn($this->jsonResponse([
                'items' => [
                    $this->document(number: 1001, serial: 'TK01-1001'),
                    $this->document(number: 1002, serial: 'TK01-1002'),
                ],
            ]));

        $result = $service->getOrders(350, 50, $filters);

        $this->assertSame(390, $result['total_registros']);
        $this->assertSame(0, $result['meta']['applied_offset']);
        $this->assertSame(40, $result['meta']['applied_limit']);
        $this->assertSame('month', $result['meta']['filters']['source']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('TK01-1002', $result['items'][0]['boleta']);
        $this->assertSame('TK01-1001', $result['items'][1]['boleta']);
    }

    public function test_it_returns_an_empty_page_when_offset_exceeds_filtered_total(): void
    {
        $client = Mockery::mock(BsaleClient::class);
        $service = new BsaleService($client);

        $client->shouldReceive('get')
            ->once()
            ->with('documents', Mockery::on(function (array $params): bool {
                return $params['state'] === 0 && $params['limit'] === 1;
            }))
            ->andReturn($this->jsonResponse(['count' => 30]));

        $result = $service->getOrders(50, 50);

        $this->assertSame(30, $result['total_registros']);
        $this->assertSame([], $result['items']);
        $this->assertNull($result['meta']['applied_offset']);
        $this->assertSame(0, $result['meta']['applied_limit']);
    }

    public function test_it_does_not_fetch_variant_endpoints_for_the_default_listing(): void
    {
        $client = Mockery::mock(BsaleClient::class);
        $service = new BsaleService($client);

        $client->shouldReceive('get')
            ->once()
            ->with('documents', Mockery::on(function (array $params): bool {
                return $params['state'] === 0 && $params['limit'] === 1;
            }))
            ->andReturn($this->jsonResponse(['count' => 1]));

        $client->shouldReceive('get')
            ->once()
            ->with('documents', Mockery::on(function (array $params): bool {
                return $params['state'] === 0
                    && $params['limit'] === 1
                    && $params['offset'] === 0;
            }))
            ->andReturn($this->jsonResponse([
                'items' => [
                    $this->document(
                        number: 1003,
                        serial: 'TK01-1003',
                        details: [
                            [
                                'variant' => [
                                    'id' => 999,
                                    'code' => 'SKU-999',
                                ],
                                'quantity' => 1,
                                'totalAmount' => 25,
                                'totalDiscount' => 0,
                            ],
                        ]
                    ),
                ],
            ]));

        $client->shouldNotReceive('get')
            ->withArgs(function (string $endpoint): bool {
                return str_starts_with($endpoint, 'variants/');
            });

        $result = $service->getOrders(0, 50);

        $this->assertSame('SKU-999', $result['items'][0]['prendas'][0]['nombre']);
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            new Psr7Response(
                $status,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR)
            )
        );
    }

    private function document(int $number, string $serial, array $details = []): array
    {
        return [
            'number' => $number,
            'serialNumber' => $serial,
            'generationDate' => 1711843200,
            'client' => [],
            'sellers' => [
                'items' => [
                    [
                        'firstName' => 'Ana',
                        'lastName' => 'Lopez',
                    ],
                ],
            ],
            'attributes' => [
                'items' => [],
            ],
            'payments' => [],
            'details' => [
                'items' => $details,
            ],
        ];
    }
}
