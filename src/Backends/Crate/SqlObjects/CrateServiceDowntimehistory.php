<?php
/**
 * Statusengine Worker
 * Copyright (C) 2016-2018  Daniel Ziegler
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Statusengine\Crate\SqlObjects;

use Crate\PDO\PDO;
use Statusengine\Crate;
use Statusengine\Exception\StorageBackendUnavailableExceptions;
use Statusengine\Exception\UnknownTypeException;
use Statusengine\ValueObjects\Downtime;

class CrateServiceDowntimehistory extends Crate\CrateModel {

    /**
     * @var string
     */
    protected $baseQuery = "INSERT INTO statusengine_service_downtimehistory
    (hostname, service_description, entry_time, author_name, comment_data, internal_downtime_id, triggered_by_id, is_fixed,
    duration, scheduled_start_time, scheduled_end_time, node_name %s)
    VALUES%s
    ON CONFLICT DO UPDATE SET entry_time=VALUES(entry_time), author_name=VALUES(author_name), comment_data=VALUES(comment_data),
    triggered_by_id=VALUES(triggered_by_id), is_fixed=VALUES(is_fixed), duration=VALUES(duration), scheduled_end_time=VALUES(scheduled_end_time) %s";


    /**
     * @var string
     */
    protected $baseValue = '?,?,?,?,?,?,?,?,?,?,?,?';

    /**
     * @var Crate\Crate
     */
    protected $CrateDB;

    /**
     * @var string
     */
    private $nodeName;

    /**
     * CrateServiceDowntimehistory constructor.
     * @param Crate\Crate $CrateDB
     * @param $nodeName
     */
    public function __construct(Crate\Crate $CrateDB, $nodeName) {
        $this->CrateDB = $CrateDB;
        $this->nodeName = $nodeName;
    }

    /**
     * @param Downtime $Downtime
     * @param bool $isRecursion
     * @return bool
     */
    public function saveDowntime(Downtime $Downtime, $isRecursion = false) {
        $query = $this->CrateDB->prepare($this->getQuery($Downtime));
        $i = 1;
        $query->bindValue($i++, $Downtime->getHostName());
        $query->bindValue($i++, $Downtime->getServiceDescription());
        $query->bindValue($i++, $Downtime->getEntryTime());
        $query->bindValue($i++, $Downtime->getAuthorName());
        $query->bindValue($i++, $Downtime->getCommentData());
        $query->bindValue($i++, $Downtime->getDowntimeId(), PDO::PARAM_INT);
        $query->bindValue($i++, $Downtime->getTriggeredBy(), PDO::PARAM_INT);
        $query->bindValue($i++, $Downtime->isFixed(), PDO::PARAM_BOOL);
        $query->bindValue($i++, $Downtime->getDuration(), PDO::PARAM_INT);
        $query->bindValue($i++, $Downtime->getScheduledStartTime());
        $query->bindValue($i++, $Downtime->getScheduledEndTime());
        $query->bindValue($i++, $this->nodeName);


        if ($Downtime->wasDowntimeAdded() || $Downtime->wasRestoredFromRetentionDat()) {
            $query->bindValue($i++, $Downtime->wasStarted(), PDO::PARAM_BOOL);
            $query->bindValue($i++, $Downtime->getActualStartTime(), PDO::PARAM_INT);
            $query->bindValue($i++, $Downtime->getActualEndTime(), PDO::PARAM_INT);
            $query->bindValue($i++, $Downtime->wasCancelled(), PDO::PARAM_BOOL);
        }

        if ($Downtime->wasDowntimeStarted()) {
            $query->bindValue($i++, $Downtime->wasStarted(), PDO::PARAM_BOOL);
            $query->bindValue($i++, $Downtime->getActualStartTime(), PDO::PARAM_INT);
        }

        if ($Downtime->wasDowntimeStopped() || $Downtime->wasDowntimeDeleted()) {
            $query->bindValue($i++, $Downtime->getActualEndTime(), PDO::PARAM_INT);
            $query->bindValue($i++, $Downtime->wasCancelled(), PDO::PARAM_BOOL);
        }

        try {
            return $this->CrateDB->executeQuery($query);
        } catch (StorageBackendUnavailableExceptions $Exceptions) {
            //Retry
            if ($isRecursion === false) {
                $this->saveDowntime($Downtime, true);
            }
        }
    }
    
    /**
     * @param Downtime $Downtime
     * @param bool $isRecursion
     * @return bool
     */
    public function deleteDowntime(Downtime $Downtime, $isRecursion = false){
        $sql = "DELETE FROM statusengine_service_downtimehistory 
        WHERE hostname=? AND service_description=? AND node_name=? AND scheduled_start_time=? AND internal_downtime_id=? AND duration=?";

        $query = $this->CrateDB->prepare($sql);
        $query->bindValue(1, $Downtime->getHostName());
        $query->bindValue(2, $Downtime->getServiceDescription());
        $query->bindValue(3, $this->nodeName);
        $query->bindValue(4, $Downtime->getScheduledStartTime());
        $query->bindValue(5, $Downtime->getDowntimeId());

        //We add duration, because there is/was a bug in CrateDB
        //https://github.com/crate/crate/issues/5763
        //todo - check if this was fixed :)
        //last testet on CrateDB 1.1.5 - should be fixed in 1.1.6
        $query->bindValue(6, $Downtime->getDuration());

        try {
            return $this->CrateDB->executeQuery($query);
        } catch (StorageBackendUnavailableExceptions $Exceptions) {
            //Retry
            if ($isRecursion === false) {
                $this->deleteDowntime($Downtime, true);
            }
        }
    }

    /**
     * @param Downtime $Downtime
     * @return string
     * @throws UnknownTypeException
     */
    private function getQuery(Downtime $Downtime) {
        if ($Downtime->wasDowntimeAdded() || $Downtime->wasRestoredFromRetentionDat()) {
            $dynamicFields = [
                'insert' => ['was_started', 'actual_start_time', 'actual_end_time', 'was_cancelled'],
                'update' => [
                    'was_started=VALUES(was_started)',
                    'actual_start_time=VALUES(actual_start_time)',
                    'actual_end_time=VALUES(actual_end_time)',
                    'was_cancelled=VALUES(was_cancelled)'
                ]
            ];
            return $this->buildQueryString($dynamicFields);
        }

        if ($Downtime->wasDowntimeStarted()) {
            $dynamicFields = [
                'insert' => ['was_started', 'actual_start_time'],
                'update' => ['was_started=VALUES(was_started)', 'actual_start_time=VALUES(actual_start_time)']
            ];
            return $this->buildQueryString($dynamicFields);
        }

        if ($Downtime->wasDowntimeStopped() || $Downtime->wasDowntimeDeleted()) {
            $dynamicFields = [
                'insert' => ['actual_end_time', 'was_cancelled'],
                'update' => ['actual_end_time=VALUES(actual_end_time)', 'was_cancelled=VALUES(was_cancelled)']
            ];
            return $this->buildQueryString($dynamicFields);
        }

        throw new UnknownTypeException('Downtime action/type is not supported');
    }

    /**
     * @param array $dynamicFields
     * @return string
     */
    public function buildQueryString($dynamicFields = []) {
        $placeholdersToAdd = [];
        foreach ($dynamicFields['insert'] as $field) {
            $placeholdersToAdd[] = '?';
        }
        return sprintf(
            $this->baseQuery,
            ', ' . implode(', ', $dynamicFields['insert']),
            '(' . $this->baseValue . ',' . implode(',', $placeholdersToAdd) . ')',
            ', ' . implode(', ', $dynamicFields['update'])
        );
    }


}
