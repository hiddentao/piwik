<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Piwik_Referers
 */

class Piwik_Referers_Archiver extends Piwik_PluginsArchiver
{
    const KEYWORDS_BY_SEARCH_ENGINE_RECORD_NAME = 'Referers_keywordBySearchEngine';
    const SEARCH_ENGINE_BY_KEYWORD_RECORD_NAME = 'Referers_searchEngineByKeyword';
    const KEYWORD_BY_CAMPAIGN_RECORD_NAME = 'Referers_keywordByCampaign';
    const URL_BY_WEBSITE_RECORD_NAME = 'Referers_urlByWebsite';
    const REFERER_TYPE_RECORD_NAME = 'Referers_type';

    const METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME = 'Referers_distinctSearchEngines';
    const METRIC_DISTINCT_KEYWORD_RECORD_NAME = 'Referers_distinctKeywords';
    const METRIC_DISTINCT_CAMPAIGN_RECORD_NAME = 'Referers_distinctCampaigns';
    const METRIC_DISTINCT_WEBSITE_RECORD_NAME = 'Referers_distinctWebsites';
    const METRIC_DISTINCT_URLS_RECORD_NAME = 'Referers_distinctWebsitesUrls';

    protected $columnToSortByBeforeTruncation;
    protected $maximumRowsInDataTableLevelZero;
    protected $maximumRowsInSubDataTable;
    protected $metricsBySearchEngine = array();
    protected $metricsByKeyword = array();
    protected $metricsBySearchEngineAndKeyword = array();
    protected $metricsByKeywordAndSearchEngine = array();
    protected $metricsByWebsite = array();
    protected $metricsByWebsiteAndUrl = array();
    protected $metricsByCampaignAndKeyword = array();
    protected $metricsByCampaign = array();
    protected $metricsByType = array();
    protected $distinctUrls = array();

    function __construct($processor)
    {
        parent::__construct($processor);
        $this->columnToSortByBeforeTruncation = Piwik_Archive::INDEX_NB_VISITS;
        $this->maximumRowsInDataTableLevelZero = Piwik_Config::getInstance()->General['datatable_archiving_maximum_rows_referers'];
        $this->maximumRowsInSubDataTable = Piwik_Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_referers'];
    }

    public function archiveDay()
    {
        $query = $this->getProcessor()->queryVisitsByDimension(array("referer_type", "referer_name", "referer_keyword", "referer_url"));
        $this->aggregateFromVisits($query);

        $query = $this->getProcessor()->queryConversionsByDimension(array("referer_type", "referer_name", "referer_keyword"));
        $this->aggregateFromConversions($query);

        Piwik_PostEvent('Referers.archiveDay', $this);
        $this->recordDayReports();
    }

    protected function aggregateFromVisits($query)
    {
        while ($row = $query->fetch()) {
            $this->makeRefererTypeNonEmpty($row);
            $this->aggregateVisit($row);
            $this->aggregateVisitByType($row);
        }
    }

    protected function makeRefererTypeNonEmpty(&$row)
    {
        if (empty($row['referer_type'])) {
            $row['referer_type'] = Piwik_Common::REFERER_TYPE_DIRECT_ENTRY;
        }
    }

    protected function aggregateVisit($row)
    {
        switch ($row['referer_type']) {
            case Piwik_Common::REFERER_TYPE_SEARCH_ENGINE:
                $this->aggregateVisitBySearchEngine($row);
                break;

            case Piwik_Common::REFERER_TYPE_WEBSITE:
                $this->aggregateVisitByWebsite($row);
                break;

            case Piwik_Common::REFERER_TYPE_CAMPAIGN:
                $this->aggregateVisitByCampaign($row);
                break;

            case Piwik_Common::REFERER_TYPE_DIRECT_ENTRY:
                // direct entry are aggregated below in $this->metricsByType array
                break;

            default:
                throw new Exception("Non expected referer_type = " . $row['referer_type']);
                break;
        }
    }

    protected function aggregateVisitBySearchEngine($row)
    {
        if (empty($row['referer_keyword'])) {
            $row['referer_keyword'] = Piwik_Referers_API::LABEL_KEYWORD_NOT_DEFINED;
        }
        if (!isset($this->metricsBySearchEngine[$row['referer_name']])) {
            $this->metricsBySearchEngine[$row['referer_name']] = $this->getProcessor()->makeEmptyRow();
        }
        if (!isset($this->metricsByKeyword[$row['referer_keyword']])) {
            $this->metricsByKeyword[$row['referer_keyword']] = $this->getProcessor()->makeEmptyRow();
        }
        if (!isset($this->metricsBySearchEngineAndKeyword[$row['referer_name']][$row['referer_keyword']])) {
            $this->metricsBySearchEngineAndKeyword[$row['referer_name']][$row['referer_keyword']] = $this->getProcessor()->makeEmptyRow();
        }
        if (!isset($this->metricsByKeywordAndSearchEngine[$row['referer_keyword']][$row['referer_name']])) {
            $this->metricsByKeywordAndSearchEngine[$row['referer_keyword']][$row['referer_name']] = $this->getProcessor()->makeEmptyRow();
        }

        $this->getProcessor()->sumMetrics($row, $this->metricsBySearchEngine[$row['referer_name']]);
        $this->getProcessor()->sumMetrics($row, $this->metricsByKeyword[$row['referer_keyword']]);
        $this->getProcessor()->sumMetrics($row, $this->metricsBySearchEngineAndKeyword[$row['referer_name']][$row['referer_keyword']]);
        $this->getProcessor()->sumMetrics($row, $this->metricsByKeywordAndSearchEngine[$row['referer_keyword']][$row['referer_name']]);
    }

    protected function aggregateVisitByWebsite($row)
    {
        if (!isset($this->metricsByWebsite[$row['referer_name']])) {
            $this->metricsByWebsite[$row['referer_name']] = $this->getProcessor()->makeEmptyRow();
        }
        $this->getProcessor()->sumMetrics($row, $this->metricsByWebsite[$row['referer_name']]);

        if (!isset($this->metricsByWebsiteAndUrl[$row['referer_name']][$row['referer_url']])) {
            $this->metricsByWebsiteAndUrl[$row['referer_name']][$row['referer_url']] = $this->getProcessor()->makeEmptyRow();
        }
        $this->getProcessor()->sumMetrics($row, $this->metricsByWebsiteAndUrl[$row['referer_name']][$row['referer_url']]);

        $urlHash = substr(md5($row['referer_url']), 0, 10);
        if (!isset($this->distinctUrls[$urlHash])) {
            $this->distinctUrls[$urlHash] = true;
        }
    }

    protected function aggregateVisitByCampaign($row)
    {
        if (!empty($row['referer_keyword'])) {
            if (!isset($this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']])) {
                $this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']] = $this->getProcessor()->makeEmptyRow();
            }
            $this->getProcessor()->sumMetrics($row, $this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']]);
        }
        if (!isset($this->metricsByCampaign[$row['referer_name']])) {
            $this->metricsByCampaign[$row['referer_name']] = $this->getProcessor()->makeEmptyRow();
        }
        $this->getProcessor()->sumMetrics($row, $this->metricsByCampaign[$row['referer_name']]);
    }

    protected function aggregateVisitByType($row)
    {
        if (!isset($this->metricsByType[$row['referer_type']])) {
            $this->metricsByType[$row['referer_type']] = $this->getProcessor()->makeEmptyRow();
        }
        $this->getProcessor()->sumMetrics($row, $this->metricsByType[$row['referer_type']]);
    }

    protected function aggregateFromConversions($query)
    {
        if ($query === false) {
            return;
        }
        while ($row = $query->fetch()) {
            $this->makeRefererTypeNonEmpty($row);

            $skipAggregateByType = $this->aggregateConversion($row);
            if (!$skipAggregateByType) {
                $this->aggregateConversionByType($row);
            }
        }

        $this->getProcessor()->enrichMetricsWithConversions($this->metricsByType);
        $this->getProcessor()->enrichMetricsWithConversions($this->metricsBySearchEngine);
        $this->getProcessor()->enrichMetricsWithConversions($this->metricsByKeyword);
        $this->getProcessor()->enrichMetricsWithConversions($this->metricsByWebsite);
        $this->getProcessor()->enrichMetricsWithConversions($this->metricsByCampaign);
        $this->getProcessor()->enrichPivotMetricsWithConversions($this->metricsByCampaignAndKeyword);
    }

    protected function aggregateConversion($row)
    {
        $skipAggregateByType = false;
        switch ($row['referer_type']) {
            case Piwik_Common::REFERER_TYPE_SEARCH_ENGINE:
                $this->aggregateConversionBySearchEngine($row);
                break;

            case Piwik_Common::REFERER_TYPE_WEBSITE:
                $this->aggregateConversionByWebsite($row);
                break;

            case Piwik_Common::REFERER_TYPE_CAMPAIGN:
                $this->aggregateConversionByCampaign($row);
                break;

            case Piwik_Common::REFERER_TYPE_DIRECT_ENTRY:
                // Direct entry, no sub dimension
                break;

            default:
                // The referer type is user submitted for goal conversions, we ignore any malformed value
                // Continue to the next while iteration
                $skipAggregateByType = true;
                break;
        }
        return $skipAggregateByType;
    }

    protected function aggregateConversionBySearchEngine($row)
    {
        if (empty($row['referer_keyword'])) {
            $row['referer_keyword'] = Piwik_Referers_API::LABEL_KEYWORD_NOT_DEFINED;
        }
        if (!isset($this->metricsBySearchEngine[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
            $this->metricsBySearchEngine[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
        }
        if (!isset($this->metricsByKeyword[$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
            $this->metricsByKeyword[$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
        }

        $this->getProcessor()->sumGoalMetrics($row, $this->metricsBySearchEngine[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
        $this->getProcessor()->sumGoalMetrics($row, $this->metricsByKeyword[$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
    }

    protected function aggregateConversionByWebsite($row)
    {
        if (!isset($this->metricsByWebsite[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
            $this->metricsByWebsite[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
        }
        $this->getProcessor()->sumGoalMetrics($row, $this->metricsByWebsite[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
    }

    protected function aggregateConversionByCampaign($row)
    {
        if (!empty($row['referer_keyword'])) {
            if (!isset($this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
                $this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
            }
            $this->getProcessor()->sumGoalMetrics($row, $this->metricsByCampaignAndKeyword[$row['referer_name']][$row['referer_keyword']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
        }
        if (!isset($this->metricsByCampaign[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
            $this->metricsByCampaign[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
        }
        $this->getProcessor()->sumGoalMetrics($row, $this->metricsByCampaign[$row['referer_name']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
    }

    protected function aggregateConversionByType($row)
    {
        if (!isset($this->metricsByType[$row['referer_type']][Piwik_Archive::INDEX_GOALS][$row['idgoal']])) {
            $this->metricsByType[$row['referer_type']][Piwik_Archive::INDEX_GOALS][$row['idgoal']] = $this->getProcessor()->makeEmptyGoalRow($row['idgoal']);
        }
        $this->getProcessor()->sumGoalMetrics($row, $this->metricsByType[$row['referer_type']][Piwik_Archive::INDEX_GOALS][$row['idgoal']]);
    }

    /**
     * Records the daily stats (numeric or datatable blob) into the archive tables.
     *
     * @param Piwik_ArchiveProcessing $this->getProcessor()
     */
    protected function recordDayReports()
    {
        $this->recordDayNumeric();
        $this->recordDayBlobs();
    }

    protected function recordDayNumeric()
    {
        $numericRecords = array(
            self::METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME => count($this->metricsBySearchEngineAndKeyword),
            self::METRIC_DISTINCT_KEYWORD_RECORD_NAME       => count($this->metricsByKeywordAndSearchEngine),
            self::METRIC_DISTINCT_CAMPAIGN_RECORD_NAME      => count($this->metricsByCampaign),
            self::METRIC_DISTINCT_WEBSITE_RECORD_NAME       => count($this->metricsByWebsite),
            self::METRIC_DISTINCT_URLS_RECORD_NAME          => count($this->distinctUrls),
        );

        foreach ($numericRecords as $name => $value) {
            $this->getProcessor()->insertNumericRecord($name, $value);
        }
    }

    protected function recordDayBlobs()
    {
        $table = new Piwik_DataTable();
        $table->addRowsFromArrayWithIndexLabel($this->metricsByType);
        $this->getProcessor()->insertBlobRecord(self::REFERER_TYPE_RECORD_NAME, $table->getSerialized());

        $blobRecords = array(
            self::KEYWORDS_BY_SEARCH_ENGINE_RECORD_NAME => $this->getProcessor()->getDataTableWithSubtablesFromArraysIndexedByLabel($this->metricsBySearchEngineAndKeyword, $this->metricsBySearchEngine),
            self::SEARCH_ENGINE_BY_KEYWORD_RECORD_NAME  => $this->getProcessor()->getDataTableWithSubtablesFromArraysIndexedByLabel($this->metricsByKeywordAndSearchEngine, $this->metricsByKeyword),
            self::KEYWORD_BY_CAMPAIGN_RECORD_NAME       => $this->getProcessor()->getDataTableWithSubtablesFromArraysIndexedByLabel($this->metricsByCampaignAndKeyword, $this->metricsByCampaign),
            self::URL_BY_WEBSITE_RECORD_NAME            => $this->getProcessor()->getDataTableWithSubtablesFromArraysIndexedByLabel($this->metricsByWebsiteAndUrl, $this->metricsByWebsite),
        );
        foreach ($blobRecords as $recordName => $table) {
            $blob = $table->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
            $this->getProcessor()->insertBlobRecord($recordName, $blob);
        }
    }

    public function archivePeriod()
    {
        $dataTableToSum = array(
            self::REFERER_TYPE_RECORD_NAME,
            self::KEYWORDS_BY_SEARCH_ENGINE_RECORD_NAME,
            self::SEARCH_ENGINE_BY_KEYWORD_RECORD_NAME,
            self::KEYWORD_BY_CAMPAIGN_RECORD_NAME,
            self::URL_BY_WEBSITE_RECORD_NAME,
        );
        $nameToCount = $this->getProcessor()->archiveDataTable($dataTableToSum, null, $this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);

        $mappingFromArchiveName = array(
            self::METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME =>
            array('typeCountToUse' => 'level0',
                  'nameTableToUse' => self::KEYWORDS_BY_SEARCH_ENGINE_RECORD_NAME,
            ),
            self::METRIC_DISTINCT_KEYWORD_RECORD_NAME       =>
            array('typeCountToUse' => 'level0',
                  'nameTableToUse' => self::SEARCH_ENGINE_BY_KEYWORD_RECORD_NAME,
            ),
            self::METRIC_DISTINCT_CAMPAIGN_RECORD_NAME      =>
            array('typeCountToUse' => 'level0',
                  'nameTableToUse' => self::KEYWORD_BY_CAMPAIGN_RECORD_NAME,
            ),
            self::METRIC_DISTINCT_WEBSITE_RECORD_NAME       =>
            array('typeCountToUse' => 'level0',
                  'nameTableToUse' => self::URL_BY_WEBSITE_RECORD_NAME,
            ),
            self::METRIC_DISTINCT_URLS_RECORD_NAME          =>
            array('typeCountToUse' => 'recursive',
                  'nameTableToUse' => self::URL_BY_WEBSITE_RECORD_NAME,
            ),
        );

        foreach ($mappingFromArchiveName as $name => $infoMapping) {
            $typeCountToUse = $infoMapping['typeCountToUse'];
            $nameTableToUse = $infoMapping['nameTableToUse'];

            if ($typeCountToUse == 'recursive') {

                $countValue = $nameToCount[$nameTableToUse]['recursive']
                    - $nameToCount[$nameTableToUse]['level0'];
            } else {
                $countValue = $nameToCount[$nameTableToUse]['level0'];
            }
            $this->getProcessor()->insertNumericRecord($name, $countValue);
        }
    }
}