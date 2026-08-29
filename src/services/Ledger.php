<?php

declare(strict_types=1);

namespace justinholtweb\subscribr\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\models\LogEntry;
use justinholtweb\subscribr\records\EventRecord;
use yii\base\Component;

/**
 * The subscription history.
 *
 * Append-only, and read by both the CP timeline and the subscriber's own portal. There is no
 * second, gentler history for the customer: if support paused somebody's subscription, the
 * subscriber can see that, and who did it. A billing dispute is won or lost on this table.
 */
class Ledger extends Component
{
    /**
     * Record something.
     *
     * `$userId` is who *did* it, which for a renewal is nobody — attributing an automatic charge
     * to the subscriber would be a lie that matters when it is quoted back in a chargeback.
     */
    public function log(
        int $subscriptionId,
        string $type,
        ?string $message = null,
        array $data = [],
        ?string $source = null,
        ?int $userId = null,
    ): LogEntry {
        $source ??= $this->currentSource();

        if ($userId === null && in_array($source, [LogEntry::SOURCE_CP, LogEntry::SOURCE_PORTAL], true)) {
            $userId = Craft::$app->getUser()->getIdentity()?->id;
        }

        $record = new EventRecord();
        $record->subscriptionId = $subscriptionId;
        $record->type = $type;
        $record->message = $message;
        $record->data = $data === [] ? null : Json::encode($data);
        $record->userId = $userId;
        $record->source = $source;
        $record->save(false);

        return $this->_toModel($record->toArray());
    }

    /**
     * Where the current request came from.
     *
     * The console branch is checked first because `getIsCpRequest()` does not exist on a console
     * application — asking is a fatal error, not a false, which is how a renewal run started from
     * cron takes the whole queue down. Queue jobs pass their source in explicitly rather than
     * being guessed at from here.
     */
    public function currentSource(): string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return LogEntry::SOURCE_CONSOLE;
        }

        if ($request->getIsCpRequest()) {
            return LogEntry::SOURCE_CP;
        }

        return LogEntry::SOURCE_PORTAL;
    }

    /** @return LogEntry[] */
    public function getEntries(int $subscriptionId, int $limit = 100): array
    {
        $rows = $this->_query()
            ->where(['subscriptionId' => $subscriptionId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row): LogEntry => $this->_toModel($row), $rows);
    }

    /** @return LogEntry[] */
    public function getEntriesOfType(int $subscriptionId, string|array $type, int $limit = 50): array
    {
        $rows = $this->_query()
            ->where(['subscriptionId' => $subscriptionId, 'type' => $type])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row): LogEntry => $this->_toModel($row), $rows);
    }

    /**
     * How many entries of a type were written since `$since`.
     *
     * This is how "three skips a year" is enforced, rather than a counter column: a counter has to
     * be reset by something, and nothing is reliably running on 1 January.
     */
    public function countSince(int $subscriptionId, string|array $type, DateTime $since): int
    {
        return (int)$this->_query()
            ->where(['subscriptionId' => $subscriptionId, 'type' => $type])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)])
            ->count('[[id]]');
    }

    /** Recent activity across every subscription, for the dashboard. @return LogEntry[] */
    public function getRecent(int $limit = 25, ?array $types = null): array
    {
        $query = $this->_query()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($types) {
            $query->where(['type' => $types]);
        }

        return array_map(fn(array $row): LogEntry => $this->_toModel($row), $query->all());
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'id',
                'subscriptionId',
                'type',
                'message',
                'data',
                'userId',
                'source',
                'dateCreated',
                'uid',
            ])
            ->from([Table::EVENTS]);
    }

    private function _toModel(array $row): LogEntry
    {
        $entry = new LogEntry();
        $entry->id = isset($row['id']) ? (int)$row['id'] : null;
        $entry->subscriptionId = (int)$row['subscriptionId'];
        $entry->type = (string)$row['type'];
        $entry->message = $row['message'] ?? null;
        $entry->setData($row['data'] ?? null);
        $entry->userId = isset($row['userId']) ? (int)$row['userId'] : null;
        $entry->source = (string)($row['source'] ?? LogEntry::SOURCE_CONSOLE);
        $entry->dateCreated = isset($row['dateCreated']) ? DateTimeHelper::toDateTime($row['dateCreated']) ?: null : null;
        $entry->uid = $row['uid'] ?? null;

        return $entry;
    }
}
