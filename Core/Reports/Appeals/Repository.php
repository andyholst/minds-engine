<?php
namespace Minds\Core\Reports\Appeals;

use Cassandra;
use Cassandra\Bigint;
use Cassandra\Type;
use Cassandra\Type\Map;
use Cassandra\Timestamp;
use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Data;
use Minds\Core\Data\Cassandra\Prepared\Custom as Prepared;
use Minds\Entities;
use Minds\Entities\DenormalizedEntity;
use Minds\Entities\NormalizedEntity;
use Minds\Common\Repository\Response;
use Minds\Core\Reports\Repository as ReportsRepository;


class Repository
{
    /** @var Data\Cassandra\Client $cql */
    protected $cql;

    /** @var ReportsRepository $reportsRepository */
    private $reportsRepository;

    public function __construct($cql = null, $reportsRepository = null)
    {
        $this->cql = $cql ?: Di::_()->get('Database\Cassandra\Client');
        $this->reportsRepository = $reportsRepository ?: new ReportsRepository;
    }

    /**
     * Return a list of appeals
     * @param array $options 'limit', 'offset', 'state'
     * @return array
     */
    public function getList(array $opts = [])
    {
        $opts = array_merge([
            'limit' => 1200,
            'offset' => '',
            'state' => '',
            'owner_guid' => null,
            'showAppealed' => false,
        ], $opts);

        $statement = "SELECT * FROM moderation_reports_by_entity_owner_guid
            WHERE entity_owner_guid = ?";

        $values = [
            new Bigint($opts['owner_guid']),
        ];

        $prepared = new Prepared();
        $prepared->query($statement, $values);

        $results = $this->cql->request($prepared);

        $response = new Response();

        foreach ($results as $row) {
            $report = $this->reportsRepository->buildFromRow($row);
            $appeal = new Appeal;
            $appeal
                ->setTimestamp($report->getAppealTimestamp())
                ->setReport($report)
                ->setNote($report->getAppealNote());
            $response[] = $appeal;
        }
        return $response;
    }

    /**
     * Add an appeal
     * @param Appeal $appeal
     * @return boolean
     */
    public function add(Appeal $appeal)
    {
        $statement = "UPDATE moderation_reports
            SET appeal_note = ?
            SET state = ?
            SET state_changes += ?
            WHERE entity_urn = ?
            AND reason_code = ?
            AND sub_reason_code = ?
            AND timestamp = ?";

        $values = [
            $appeal->getNote(),
            'appealed',
            (new Map(Type::text(), Type::bigint()))
                ->set('appealed', $appeal->getTimestamp()),
            $appeal->getReport()->getEntityUrn(),
            (float) $appeal->getReport()->getReasonCode(),
            (float) $appeal->getReport()->getSubReasonCode(),
            new Timestamp($appeal->getReport()->getTimestamp()),
        ];

        $prepared = new Prepared();
        $prepared->query($statement, $values);

        return (bool) $this->cql->request($prepared);
    }

}
