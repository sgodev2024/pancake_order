<?php

namespace App\Console\Commands;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RepairCustomerCareAddresses extends Command
{
    private const DEFAULT_DISPLAY_LIMIT = 50;

    private const CHUNK_SIZE = 100;

    protected $signature = 'customer-care:repair-addresses
                            {--execute : Apply only deterministic eligible address repairs}
                            {--ids= : Comma-separated CustomerCare IDs to consider}
                            {--shop-id= : Restrict the scan to one shop ID}
                            {--limit=50 : Maximum targeted candidates to display; the full candidate set is scanned}';

    protected $description = 'Preview or repair blank historical CustomerCare addresses from deterministic local Order sources';

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ids = $this->parseIds($this->option('ids'));
        $shopId = $this->parsePositiveIntegerOption('shop-id');
        $displayLimit = $this->parsePositiveIntegerOption('limit', self::DEFAULT_DISPLAY_LIMIT);

        if ($ids === false || $shopId === false || $displayLimit === false) {
            return self::INVALID;
        }

        $analysis = $this->analyze($ids, $shopId, $displayLimit);

        $this->components->info(
            $this->option('execute')
                ? 'EXECUTE — only currently eligible rows will be updated.'
                : 'PREVIEW ONLY — no database rows will be modified.'
        );

        if ($analysis['rows'] !== []) {
            $this->table(
                [
                    'CustomerCare ID',
                    'Order ID',
                    'Shop ID',
                    'Current Address State',
                    'Order Address State',
                    'Action',
                ],
                $analysis['rows']
            );
        }

        if ($analysis['display_truncated']) {
            $this->line("Displayed: {$displayLimit} of {$analysis['summary']['scanned']} targeted candidates.");
        }

        $repaired = 0;

        if ($this->option('execute') && $analysis['candidates'] !== []) {
            $repaired = $this->executeRepairs($analysis['candidates']);
        }

        $summary = $analysis['summary'];
        $this->newLine();
        $this->line("Scanned: {$summary['scanned']}");
        $this->line("Eligible: {$summary['eligible']}");
        $this->line("Would repair: {$summary['eligible']}");
        $this->line("NO_SOURCE: {$summary['no_source']}");
        $this->line("AMBIGUOUS: {$summary['ambiguous']}");
        $this->line("ORDER_ADDRESS_BLANK: {$summary['order_address_blank']}");
        $this->line("SHOP_MISMATCH: {$summary['shop_mismatch']}");
        $this->line("Already populated (not candidates): {$summary['already_populated']}");

        if ($analysis['unknown_ids'] !== []) {
            $this->line('Unknown requested IDs: '.implode(',', $analysis['unknown_ids']));
        }

        $this->line("Repaired: {$repaired}");

        return self::SUCCESS;
    }

    /**
     * @return list<int>|false|null
     */
    private function parseIds(mixed $value): array|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->components->error('--ids must be a comma-separated list of positive CustomerCare IDs.');

            return false;
        }

        $ids = [];

        foreach (explode(',', $value) as $token) {
            $token = trim($token);

            if ($token === '' || preg_match('/^[1-9]\d*$/', $token) !== 1) {
                $this->components->error('--ids must be a comma-separated list of positive CustomerCare IDs.');

                return false;
            }

            $id = filter_var($token, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($id === false) {
                $this->components->error('--ids contains an invalid CustomerCare ID.');

                return false;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private function parsePositiveIntegerOption(string $name, ?int $default = null): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            $this->components->error("--{$name} must be a positive integer.");

            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($parsed === false) {
            $this->components->error("--{$name} must be a positive integer.");

            return false;
        }

        return $parsed;
    }

    /**
     * @param  list<int>|null  $ids
     * @return array{
     *     rows: list<array<int, int|string>>,
     *     candidates: list<array{customer_care_id: int, order_id: int, link_strategy: string}>,
     *     summary: array<string, int>,
     *     unknown_ids: list<int>,
     *     display_truncated: bool
     * }
     */
    private function analyze(?array $ids, ?int $shopId, int $displayLimit): array
    {
        $rows = [];
        $candidates = [];
        $summary = [
            'scanned' => 0,
            'eligible' => 0,
            'no_source' => 0,
            'ambiguous' => 0,
            'order_address_blank' => 0,
            'shop_mismatch' => 0,
            'already_populated' => 0,
        ];

        $requestedCustomerCares = $ids === null
            ? collect()
            : CustomerCare::query()
                ->select(['id', 'shop_id', 'customer_addresss'])
                ->whereIn('id', $ids)
                ->when($shopId !== null, fn ($builder) => $builder->where('shop_id', $shopId))
                ->get();
        $foundIds = $requestedCustomerCares->pluck('id')->map(fn ($id) => (int) $id)->all();
        $summary['already_populated'] = $requestedCustomerCares
            ->filter(fn (CustomerCare $customerCare) => ! $this->isBlank($customerCare->customer_addresss))
            ->count();

        $query = CustomerCare::query()
            ->select([
                'id',
                'shop_id',
                'pancake_customer_id',
                'pancake_order_id',
                'customer_addresss',
            ])
            ->when($ids !== null, fn ($builder) => $builder->whereIn('id', $ids))
            ->when($shopId !== null, fn ($builder) => $builder->where('shop_id', $shopId))
            ->where(function ($builder) {
                $builder->whereNull('customer_addresss')
                    ->orWhereRaw("TRIM(customer_addresss) = ''");
            })
            ->orderBy('id');

        $query->chunkById(self::CHUNK_SIZE, function (EloquentCollection $customerCares) use (
            &$rows,
            &$candidates,
            &$summary,
            $displayLimit
        ): void {
            $evaluations = $this->evaluate($customerCares);

            foreach ($customerCares as $customerCare) {
                $evaluation = $evaluations[$customerCare->getKey()];
                $summary['scanned']++;

                if (count($rows) < $displayLimit) {
                    $rows[] = $this->tableRow($customerCare, $evaluation);
                }

                match ($evaluation['action']) {
                    'WOULD_REPAIR' => $this->addCandidate($candidates, $summary, $customerCare, $evaluation),
                    'ORDER_ADDRESS_BLANK' => $summary['order_address_blank']++,
                    'AMBIGUOUS' => $summary['ambiguous']++,
                    'SHOP_MISMATCH' => $summary['shop_mismatch']++,
                    default => $summary['no_source']++,
                };
            }
        }, 'id');

        $unknownIds = $ids === null
            ? []
            : array_values(array_diff($ids, $foundIds));

        return [
            'rows' => $rows,
            'candidates' => $candidates,
            'summary' => $summary,
            'unknown_ids' => $unknownIds,
            'display_truncated' => $summary['scanned'] > count($rows),
        ];
    }

    /**
     * @param  EloquentCollection<int, CustomerCare>  $customerCares
     * @return array<int, array{action: string, order: ?Order, link_strategy: ?string}>
     */
    private function evaluate(EloquentCollection $customerCares, bool $lock = false): array
    {
        $careIds = $customerCares->pluck('id')->map(fn ($id) => (int) $id)->all();

        $assignmentQuery = CustomerCareAssignment::query()
            ->whereIn('customer_care_id', $careIds)
            ->select(['customer_care_id', 'source_type', 'source_id', 'shop_id'])
            ->orderBy('id');

        if ($lock) {
            $assignmentQuery->lockForUpdate();
        }

        $assignmentsByCare = $assignmentQuery->get()->groupBy('customer_care_id');
        $sourceOrderIds = $assignmentsByCare
            ->flatten(1)
            ->filter(fn (CustomerCareAssignment $assignment) => $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER)
            ->pluck('source_id')
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values();

        $sourceOrders = $this->loadOrdersByIds($sourceOrderIds, $lock);
        $evaluations = [];

        foreach ($customerCares as $customerCare) {
            $assignments = $assignmentsByCare->get($customerCare->getKey(), collect());

            if ($assignments->isNotEmpty()) {
                $evaluations[$customerCare->getKey()] = $this->evaluateAssignmentSource(
                    $customerCare,
                    $assignments,
                    $sourceOrders
                );

                continue;
            }

            $evaluations[$customerCare->getKey()] = $this->skipped('NO_SOURCE');
        }

        return $evaluations;
    }

    /**
     * @param  Collection<int, CustomerCareAssignment>  $assignments
     * @param  Collection<int, Order>  $sourceOrders
     * @return array{action: string, order: ?Order, link_strategy: ?string}
     */
    private function evaluateAssignmentSource(
        CustomerCare $customerCare,
        Collection $assignments,
        Collection $sourceOrders
    ): array {
        $orderAssignments = $assignments
            ->filter(fn (CustomerCareAssignment $assignment) => $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER);
        $sourceIds = $orderAssignments
            ->pluck('source_id')
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values();

        if ($sourceIds->count() > 1) {
            return $this->skipped('AMBIGUOUS');
        }

        if ($sourceIds->count() !== 1) {
            return $this->skipped('NO_SOURCE');
        }

        $order = $sourceOrders->get((int) $sourceIds->first());

        if ($order === null) {
            return $this->skipped('NO_SOURCE');
        }

        if ($orderAssignments->contains(
            fn (CustomerCareAssignment $assignment) => (int) $assignment->shop_id !== (int) $customerCare->shop_id
        ) || (int) $order->shop_id !== (int) $customerCare->shop_id) {
            return $this->skipped('SHOP_MISMATCH', $order, 'CCA_EXACT_ORDER_ID');
        }

        return $this->fromOrder($order, 'CCA_EXACT_ORDER_ID');
    }

    /**
     * @param  Collection<int, int>  $orderIds
     * @return Collection<int, Order>
     */
    private function loadOrdersByIds(Collection $orderIds, bool $lock): Collection
    {
        if ($orderIds->isEmpty()) {
            return collect();
        }

        $query = Order::query()
            ->whereIn('id', $orderIds)
            ->select(['id', 'shop_id', 'pancake_order_id', 'pancake_customer_id', 'customer_address']);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->keyBy('id');
    }

    /**
     * @return array{action: string, order: ?Order, link_strategy: ?string}
     */
    private function fromOrder(Order $order, string $linkStrategy): array
    {
        if ($this->isBlank($order->customer_address)) {
            return [
                'action' => 'ORDER_ADDRESS_BLANK',
                'order' => $order,
                'link_strategy' => $linkStrategy,
            ];
        }

        return [
            'action' => 'WOULD_REPAIR',
            'order' => $order,
            'link_strategy' => $linkStrategy,
        ];
    }

    /**
     * @return array{action: string, order: ?Order, link_strategy: ?string}
     */
    private function skipped(string $action, ?Order $order = null, ?string $linkStrategy = null): array
    {
        return [
            'action' => $action,
            'order' => $order,
            'link_strategy' => $linkStrategy,
        ];
    }

    /**
     * @param  array{action: string, order: ?Order, link_strategy: ?string}  $evaluation
     * @return array<int, int|string>
     */
    private function tableRow(CustomerCare $customerCare, array $evaluation): array
    {
        return [
            (int) $customerCare->getKey(),
            $evaluation['order']?->getKey() ?? '—',
            (int) $customerCare->shop_id,
            $this->isBlank($customerCare->customer_addresss) ? 'BLANK' : 'POPULATED',
            $evaluation['order'] === null
                ? '—'
                : ($this->isBlank($evaluation['order']->customer_address) ? 'BLANK' : 'POPULATED'),
            $evaluation['action'],
        ];
    }

    /**
     * @param  list<array{customer_care_id: int, order_id: int, link_strategy: string}>  $candidates
     * @param  array<string, int>  $summary
     * @param  array{action: string, order: ?Order, link_strategy: ?string}  $evaluation
     */
    private function addCandidate(
        array &$candidates,
        array &$summary,
        CustomerCare $customerCare,
        array $evaluation
    ): void {
        $candidates[] = [
            'customer_care_id' => (int) $customerCare->getKey(),
            'order_id' => (int) $evaluation['order']->getKey(),
            'link_strategy' => (string) $evaluation['link_strategy'],
        ];
        $summary['eligible']++;
    }

    /**
     * @param  list<array{customer_care_id: int, order_id: int, link_strategy: string}>  $candidates
     */
    private function executeRepairs(array $candidates): int
    {
        $repaired = 0;

        foreach (array_chunk($candidates, self::CHUNK_SIZE) as $candidateChunk) {
            $repaired += DB::transaction(function () use ($candidateChunk): int {
                $candidateIds = collect($candidateChunk)->pluck('customer_care_id')->all();
                $customerCares = CustomerCare::query()
                    ->whereIn('id', $candidateIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get([
                        'id',
                        'shop_id',
                        'pancake_customer_id',
                        'pancake_order_id',
                        'customer_addresss',
                    ]);
                $evaluations = $this->evaluate($customerCares, true);
                $updated = 0;

                foreach ($candidateChunk as $candidate) {
                    $customerCare = $customerCares->firstWhere('id', $candidate['customer_care_id']);
                    $evaluation = $evaluations[$candidate['customer_care_id']] ?? null;

                    if ($customerCare === null
                        || $evaluation === null
                        || $evaluation['action'] !== 'WOULD_REPAIR'
                        || (int) $evaluation['order']->getKey() !== $candidate['order_id']
                        || $evaluation['link_strategy'] !== $candidate['link_strategy']) {
                        continue;
                    }

                    $affected = DB::table('customer_cares')
                        ->where('id', $customerCare->getKey())
                        ->where(function ($query) {
                            $query->whereNull('customer_addresss')
                                ->orWhereRaw("TRIM(customer_addresss) = ''");
                        })
                        ->update([
                            'customer_addresss' => $evaluation['order']->customer_address,
                        ]);

                    if ($affected !== 1) {
                        continue;
                    }

                    $this->activityLogService->log(
                        'customer_care.address_repaired',
                        'system',
                        null,
                        'Hệ thống',
                        null,
                        null,
                        (int) $customerCare->shop_id,
                        null,
                        'customer_care',
                        $customerCare->getKey(),
                        null,
                        null,
                        ['customer_addresss' => 'BLANK'],
                        ['customer_addresss' => 'POPULATED'],
                        [
                            'customer_care_id' => (int) $customerCare->getKey(),
                            'source_order_id' => (int) $evaluation['order']->getKey(),
                            'shop_id' => (int) $customerCare->shop_id,
                            'link_strategy' => $evaluation['link_strategy'],
                            'repair_timestamp' => now(config('app.timezone'))->toISOString(),
                        ]
                    );

                    $updated++;
                }

                return $updated;
            });
        }

        return $repaired;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

}
