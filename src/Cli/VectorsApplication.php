<?php

declare(strict_types=1);

namespace Lateo\Explorer\Cli;

use Lateo\Explorer\Chain\Asset;
use Lateo\Explorer\Chain\ConversionResult;
use Lateo\Explorer\Chain\ConversionRule;
use Lateo\Explorer\Chain\Generation;
use Lateo\Explorer\Db\Database;
use Lateo\Explorer\Support\Clock;

/**
 * bin/vectors.php: writes the conversion vectors of the database as JSON to standard output.
 *
 * The format is the proposal of the step 4c research (format "zephyr-conversion-vectors",
 * format_version 1, marked "status": "proposal" until it is agreed with the library that reads
 * it). It reads only the database, through a read-only connection. Amounts, prices, rates and
 * every field of a pricing record are decimal strings (they can pass 2^53); heights, the
 * hard fork and the age are JSON numbers. Only checked transactions (match or mismatch) are
 * written, never one that was not recomputed.
 *
 * --all writes every checked conversion and audit, in the order of height and position.
 * --sample writes the first (lowest height, then position) transaction of each kind the
 * research lists: per stable and reserve type and fee regime, per yield type, per branch of
 * max and min (the price field), at age 1 and at age 10, per audit asset in hard forks 8 and
 * 10, and the first conversion of hard forks 5, 6 and 11. The output is the same for the
 * same database and the same date.
 *
 * --sample also writes synthetic negative vectors after the real ones: each is made by running
 * ConversionRule on a changed copy of a real entry (source.synthetic true, source.tx_hash of the
 * real transaction, source.mutation naming the change). Their expected values come from the rule,
 * not from the chain. Where the rule cannot compute (an incomplete or missing record, an age
 * outside 1 to 10, a type not allowed at that hard fork, an invalid value) expected holds null for
 * price_field, price, rate_before_fee, fee, rate, minted and remainder, valid is false, and
 * pr_complete, age and age_valid are the true values. --all writes real entries only.
 *
 * @phpstan-type Inputs array{hash:string,height:int,type:string,audit:bool,ticker:string,hard_fork:int,prh:int,burnt:string,minted:string,source:string,dest:string,record:array<string,int>|null,price_field:?string,result:ConversionResult}
 */
final class VectorsApplication
{
    public const FORMAT = 'zephyr-conversion-vectors';
    public const FORMAT_VERSION = 1;
    public const DAEMON_VERSION = '2.3.0';
    public const DAEMON_COMMIT = '3adbb517a';

    private const FROM = ' FROM conversion_checks c JOIN transactions t ON t.hash = c.tx_hash JOIN blocks b ON b.height = t.height ';
    private const CHECKED = " c.verdict IN ('match', 'mismatch') ";
    private const ORDER = ' ORDER BY t.height, t.idx ';

    private const AUDIT_NAMES = ['ZPH' => 'AUDIT_ZEPH', 'ZSD' => 'AUDIT_STABLE', 'ZRS' => 'AUDIT_RESERVE', 'ZYS' => 'AUDIT_YIELD'];

    /**
     * @param \Closure(): Database $openDatabase
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly \Closure $openDatabase,
        private readonly Clock $clock,
        private $out,
        private $err,
    ) {
    }

    public function run(bool $all): int
    {
        try {
            $db = ($this->openDatabase)();
            $hasTable = (int) $db->value("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'conversion_checks'");
            if ($hasTable === 0) {
                fwrite($this->err, "vectors: the database has no conversion checks yet\n");
                return 1;
            }
            $this->write($db, $all);
            return 0;
        } catch (\Throwable $e) {
            $own = $e instanceof \RuntimeException && !$e instanceof \PDOException;
            fwrite($this->err, 'vectors: stopped: ' . ($own ? $e->getMessage() : $e::class) . "\n");
            return 1;
        }
    }

    private function write(Database $db, bool $all): void
    {
        $generator = [
            'name' => 'lateo-explorer',
            'version' => 'rule-' . ConversionRule::VERSION,
            'date' => gmdate('Y-m-d', (int) floor($this->clock->now())),
        ];
        $out = "{\n";
        $out .= '  "format": ' . self::json(self::FORMAT) . ",\n";
        $out .= '  "format_version": ' . self::FORMAT_VERSION . ",\n";
        $out .= '  "status": "proposal",' . "\n";
        $out .= '  "daemon": ' . self::json(['name' => 'zephyr', 'version' => self::DAEMON_VERSION, 'commit' => self::DAEMON_COMMIT], 2) . ",\n";
        $out .= '  "generator": ' . self::json($generator, 2) . ",\n";
        $out .= '  "constants": ' . self::json(['coin' => ConversionRule::COIN, 'rounding_modulus' => ConversionRule::ROUNDING_MODULUS], 2) . ",\n";
        $out .= '  "vectors": [';
        fwrite($this->out, $out);

        $first = true;
        $emit = function (array $vector) use (&$first): void {
            fwrite($this->out, ($first ? "\n" : ",\n") . '    ' . self::json($vector, 4));
            $first = false;
        };
        if ($all) {
            foreach ($this->allRows($db) as $row) {
                $emit($this->real($db, $row));
            }
        } else {
            $inputs = [];
            foreach ($this->sampleRows($db) as $row) {
                $input = $this->inputs($db, $row);
                $inputs[] = $input;
                $emit($this->compose($input, $input['result']));
            }
            foreach ($this->negatives($inputs) as $vector) {
                $emit($vector);
            }
        }
        fwrite($this->out, $first ? "]\n}\n" : "\n  ]\n}\n");
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function allRows(Database $db): \Generator
    {
        $after = [-1, -1];
        while (true) {
            $rows = $db->all(
                $this->select() . self::FROM . ' WHERE ' . self::CHECKED
                . ' AND (t.height > ? OR (t.height = ? AND t.idx > ?)) ' . self::ORDER . ' LIMIT 500',
                [$after[0], $after[0], $after[1]],
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                yield $row;
            }
            $last = $rows[array_key_last($rows)];
            $after = [(int) $last['height'], (int) $last['idx']];
        }
    }

    /** @return list<array<string,mixed>> */
    private function sampleRows(Database $db): array
    {
        /** @var list<array{0:string,1:list<int|string>}> $selectors */
        $selectors = [];
        foreach (['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'] as $type) {
            foreach ([[3, 4], [5, 5], [6, 9999]] as [$low, $high]) {
                $selectors[] = ['t.tx_type = ? AND c.hard_fork BETWEEN ? AND ?', [$type, $low, $high]];
            }
        }
        foreach (['mint_yield', 'redeem_yield'] as $type) {
            $selectors[] = ['t.tx_type = ?', [$type]];
        }
        foreach ($db->all(
            'SELECT DISTINCT t.tx_type, c.price_field' . self::FROM . ' WHERE ' . self::CHECKED
            . " AND c.price_field IS NOT NULL AND t.tx_type IN ('mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve')"
            . ' ORDER BY t.tx_type, c.price_field',
        ) as $combo) {
            $selectors[] = ['t.tx_type = ? AND c.price_field = ?', [(string) $combo['tx_type'], (string) $combo['price_field']]];
        }
        foreach ([1, 10] as $age) {
            $selectors[] = ["t.tx_type <> 'audit' AND t.height - t.pricing_record_height = ?", [$age]];
        }
        foreach ($db->all(
            'SELECT DISTINCT t.source_asset, c.hard_fork' . self::FROM . ' WHERE ' . self::CHECKED
            . " AND t.tx_type = 'audit' AND c.hard_fork IN (8, 10) ORDER BY c.hard_fork, t.source_asset",
        ) as $combo) {
            $selectors[] = ["t.tx_type = 'audit' AND t.source_asset = ? AND c.hard_fork = ?", [(string) $combo['source_asset'], (int) $combo['hard_fork']]];
        }
        foreach ([5, 6, 11] as $fork) {
            $selectors[] = ["t.tx_type <> 'audit' AND c.hard_fork = ?", [$fork]];
        }

        /** @var array<string,array<string,mixed>> $picked */
        $picked = [];
        foreach ($selectors as [$where, $params]) {
            $row = $db->one(
                $this->select() . self::FROM . ' WHERE ' . self::CHECKED . ' AND ' . $where . self::ORDER . ' LIMIT 1',
                $params,
            );
            if ($row !== null) {
                $picked[(string) $row['hash']] = $row;
            }
        }
        $rows = array_values($picked);
        usort($rows, static fn (array $a, array $b): int => [(int) $a['height'], (int) $a['idx']] <=> [(int) $b['height'], (int) $b['idx']]);
        return $rows;
    }

    private function select(): string
    {
        return 'SELECT t.hash, t.height, t.idx, t.tx_type, t.source_asset, t.source_gen, t.dest_asset, t.dest_gen,
                       t.pricing_record_height, t.amount_burnt, t.amount_minted, b.major_version,
                       c.verdict, c.price_field, c.price, c.r0, c.fee, c.rate, c.expected, c.remainder';
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function real(Database $db, array $r): array
    {
        $input = $this->inputs($db, $r);
        return $this->compose($input, $input['result']);
    }

    /**
     * What a vector is made of, from a row of the checked transactions: the transaction, the
     * hard fork of its block, the record it refers to, and the stored result of the rule.
     *
     * @param array<string,mixed> $r
     * @return Inputs
     */
    private function inputs(Database $db, array $r): array
    {
        $type = (string) $r['tx_type'];
        $prh = (int) $r['pricing_record_height'];
        $audit = $type === 'audit';
        $record = null;
        if (!$audit) {
            $row = $db->one(
                'SELECT ' . implode(', ', ConversionRule::RECORD_FIELDS) . ' FROM pricing_records WHERE height = ?',
                [$prh],
            );
            if ($row === null) {
                throw new \RuntimeException('a checked conversion has no stored record');
            }
            $record = array_map('intval', $row);
        }
        return [
            'hash' => (string) $r['hash'],
            'height' => (int) $r['height'],
            'type' => $type,
            'audit' => $audit,
            'ticker' => (string) $r['source_asset'],
            'hard_fork' => (int) $r['major_version'],
            'prh' => $prh,
            'burnt' => (string) $r['amount_burnt'],
            'minted' => (string) $r['amount_minted'],
            'source' => (new Asset((string) $r['source_asset'], Generation::from((string) $r['source_gen'])))->chainName(),
            'dest' => (new Asset((string) $r['dest_asset'], Generation::from((string) $r['dest_gen'])))->chainName(),
            'record' => $record,
            'price_field' => $r['price_field'] === null ? null : (string) $r['price_field'],
            'result' => new ConversionResult(
                (string) $r['verdict'],
                ConversionRule::CHECKED,
                $r['price_field'] === null ? null : (string) $r['price_field'],
                $r['price'] === null ? null : (string) $r['price'],
                (string) $r['r0'],
                (string) $r['fee'],
                (string) $r['rate'],
                (string) $r['expected'],
                (string) $r['remainder'],
            ),
        ];
    }

    /**
     * One vector from its inputs and a result of the rule. A real entry has the stored result;
     * a synthetic one has the result of the rule on its changed inputs (then $mutation and $id
     * are given).
     *
     * @param Inputs $in
     * @return array<string,mixed>
     */
    private function compose(array $in, ConversionResult $result, ?string $mutation = null, ?string $id = null): array
    {
        $height = $in['height'];
        $audit = $in['audit'];
        $source = ['network' => 'mainnet', 'tx_hash' => $in['hash'], 'synthetic' => $mutation !== null];
        if ($mutation !== null) {
            $source['mutation'] = $mutation;
        }
        $vector = [
            'id' => $id ?? 'mainnet-' . $height . '-' . substr($in['hash'], 0, 12),
            'source' => $source,
            'hard_fork' => $in['hard_fork'],
            'block_height' => $height,
            'pricing_record_height' => $in['prh'],
            'tx_type' => $audit ? self::AUDIT_NAMES[$in['ticker']] : strtoupper($in['type']),
            'source_asset' => $in['source'],
            'dest_asset' => $in['dest'],
            'amount_burnt' => $in['burnt'],
            'amount_minted' => $in['minted'],
        ];
        if (!$audit) {
            $vector['pricing_record'] = array_map('strval', (array) $in['record']);
        }
        $age = $height - $in['prh'];
        $checked = $result->isChecked();
        $vector['expected'] = [
            'pr_complete' => $audit || ($in['record'] !== null && ConversionRule::recordComplete($in['record'], $in['hard_fork'])),
            'age' => $audit ? null : $age,
            'age_valid' => $audit || ($age >= ConversionRule::MIN_AGE && $age <= ConversionRule::MAX_AGE),
            'price_field' => $result->priceField,
            'price' => $result->price,
            'rate_before_fee' => $result->r0,
            'fee' => $result->fee,
            'rate' => $result->rate,
            'minted' => $result->expected,
            'remainder' => $result->remainder,
            'valid' => $checked && $result->verdict === ConversionResult::MATCH,
        ];
        return $vector;
    }

    /**
     * The synthetic negative vectors of the sample, after the real ones: for the first real
     * entry of every conversion type and fee regime, in the order of the real entries, five
     * mutations (and a sixth where it applies); then, for the first audit, one.
     *
     * @param list<Inputs> $inputs
     * @return list<array<string,mixed>>
     */
    private function negatives(array $inputs): array
    {
        $out = [];
        $seen = [];
        $audited = false;
        $audits = [];
        foreach ($inputs as $in) {
            if ($in['audit']) {
                if (!$audited) {
                    $audited = true;
                    $audits[] = $in;
                }
                continue;
            }
            $key = $in['type'] . '|' . RecomputeApplication::regime($in['type'], $in['hard_fork']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $n = 0;
            foreach ($this->mutations($in) as [$mutation, $changed]) {
                $out[] = $this->synthetic($in, $changed, $mutation, ++$n);
            }
        }
        foreach ($audits as $in) {
            $n = 0;
            foreach ($this->mutations($in) as [$mutation, $changed]) {
                $out[] = $this->synthetic($in, $changed, $mutation, ++$n);
            }
        }
        return $out;
    }

    /**
     * The mutations of one real entry in their order, each with the changed copy.
     *
     * @param Inputs $in
     * @return list<array{0:string,1:Inputs}>
     */
    private function mutations(array $in): array
    {
        $out = [];
        $minted = gmp_init($in['minted']);
        $out[] = ['amount_minted plus 1', ['minted' => gmp_strval(gmp_add($minted, 1))] + $in];
        if ($in['audit']) {
            return $out;
        }
        if (gmp_cmp($minted, 0) > 0) {
            $out[] = ['amount_minted minus 1', ['minted' => gmp_strval(gmp_sub($minted, 1))] + $in];
        }
        $out[] = ['age 0', ['prh' => $in['height']] + $in];
        if ($in['height'] >= 11) {
            $out[] = ['age 11', ['prh' => $in['height'] - 11] + $in];
        }
        $field = $in['price_field'];
        if ($field !== null && $in['record'] !== null) {
            $zeroed = $in['record'];
            $zeroed[$field] = 0; // the field keeps its place in the record
            $out[] = [$field . ' zero', ['record' => $zeroed] + $in];
        }
        if (RecomputeApplication::regime($in['type'], $in['hard_fork']) === 'hf >=6'
            && in_array($in['type'], ['mint_stable', 'redeem_stable', 'mint_reserve', 'redeem_reserve'], true)) {
            $older = ['hard_fork' => 5] + $in;
            $result = $this->rule($older);
            if ($result->isChecked() && $result->expected !== $in['minted']) {
                $out[] = ['hard fork 5 rule on a hard fork 6 block', $older];
            }
        }
        return $out;
    }

    /**
     * @param Inputs $in
     * @param Inputs $changed
     * @return array<string,mixed>
     */
    private function synthetic(array $in, array $changed, string $mutation, int $n): array
    {
        $id = 'synthetic-' . $in['height'] . '-' . substr($in['hash'], 0, 12) . '-' . $n;
        return $this->compose($changed, $this->rule($changed), $mutation, $id);
    }

    /**
     * The rule on the inputs of a vector.
     *
     * @param Inputs $in
     */
    private function rule(array $in): ConversionResult
    {
        return ConversionRule::check(
            $in['type'],
            $in['hard_fork'],
            $in['height'],
            $in['prh'],
            $in['burnt'],
            $in['minted'],
            $in['audit'] ? null : $in['record'],
        );
    }

    /** JSON as pretty text; $indent is the indentation of the line the value starts on. */
    private static function json(mixed $value, int $indent = 0): string
    {
        $text = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $indent === 0 ? $text : str_replace("\n", "\n" . str_repeat(' ', $indent), $text);
    }
}
