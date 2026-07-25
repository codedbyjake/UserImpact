<?php

namespace MediaWiki\Extension\UserImpact;

use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class ImpactStats {

    private const ACTIVITY_DAYS = 60;
    private const VIEW_DAYS = 60;

    public function getEditStats( User $user ): array {
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $actorId = $services->getActorNormalization()->findActorId( $user, $dbr );

        $dailyActivity = $this->emptyDailyActivity();
        $lastEdited = null;

        if ( $actorId !== null ) {
            $lastEditedRow = $dbr->newSelectQueryBuilder()
                ->select( [ 'rev_timestamp' ] )
                ->from( 'revision' )
                ->where( [ 'rev_actor' => $actorId ] )
                ->orderBy( 'rev_timestamp', 'DESC' )
                ->limit( 1 )
                ->caller( __METHOD__ )
                ->fetchRow();
            $lastEdited = $lastEditedRow ? $lastEditedRow->rev_timestamp : null;

            $since = $dbr->timestamp( strtotime( '-' . ( self::ACTIVITY_DAYS - 1 ) . ' days' ) );
            $rows = $dbr->newSelectQueryBuilder()
                ->select( [ 'rev_timestamp' ] )
                ->from( 'revision' )
                ->where( [
                    'rev_actor' => $actorId,
                    'rev_timestamp >= ' . $dbr->addQuotes( $since ),
                ] )
                ->caller( __METHOD__ )
                ->fetchResultSet();

            foreach ( $rows as $row ) {
                $date = $this->tsToDate( $row->rev_timestamp );
                if ( isset( $dailyActivity[$date] ) ) {
                    $dailyActivity[$date]++;
                }
            }
        }

        return [
            'totalEdits' => $user->getEditCount() ?? 0,
            'lastEdited' => $lastEdited,
            'streak' => $this->longestStreak( $dailyActivity ),
            'dailyActivity' => $dailyActivity,
        ];
    }

    /**
     * @return array{totalViews: int, topArticles: array<string, int>}|null Null if view
     *   stats aren't configured (no Plausible API key set) on this wiki.
     */
    public function getViewStats( User $user, int $limit = 10 ): ?array {
        $services = MediaWikiServices::getInstance();
        if ( !$services->getMainConfig()->get( 'PlausibleApiKey' ) ) {
            return null;
        }

        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $actorId = $services->getActorNormalization()->findActorId( $user, $dbr );
        if ( $actorId === null ) {
            return [ 'totalViews' => 0, 'topArticles' => [] ];
        }

        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_namespace', 'page_title' ] )
            ->distinct()
            ->from( 'revision' )
            ->join( 'page', null, 'rev_page = page_id' )
            ->where( [ 'rev_actor' => $actorId, 'page_namespace' => NS_MAIN ] )
            ->limit( 500 )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $titles = [];
        foreach ( $rows as $row ) {
            $titles[] = Title::makeTitle( $row->page_namespace, $row->page_title );
        }

        if ( !$titles ) {
            return [ 'totalViews' => 0, 'topArticles' => [] ];
        }

        /** @var PageViewService $service */
        $service = $services->getService( 'PageViewService' );
        $status = $service->getPageData( $titles, self::VIEW_DAYS, PageViewService::METRIC_VIEW );

        $totals = [];
        $data = $status->getValue();
        foreach ( $titles as $title ) {
            $key = $title->getPrefixedDBkey();
            if ( empty( $status->success[$key] ) || !isset( $data[$key] ) ) {
                continue;
            }
            $totals[$title->getPrefixedText()] = array_sum( array_filter( $data[$key], 'is_int' ) );
        }

        arsort( $totals );

        return [
            'totalViews' => array_sum( $totals ),
            'topArticles' => array_slice( $totals, 0, $limit, true ),
        ];
    }

    private function emptyDailyActivity(): array {
        $days = [];
        for ( $i = self::ACTIVITY_DAYS - 1; $i >= 0; $i-- ) {
            $days[ date( 'Y-m-d', strtotime( "-$i days" ) ) ] = 0;
        }
        return $days;
    }

    private function tsToDate( string $mwTimestamp ): string {
        return substr( $mwTimestamp, 0, 4 ) . '-' . substr( $mwTimestamp, 4, 2 ) . '-' . substr( $mwTimestamp, 6, 2 );
    }

    private function longestStreak( array $dailyActivity ): int {
        $longest = 0;
        $current = 0;
        foreach ( $dailyActivity as $count ) {
            if ( $count > 0 ) {
                $current++;
                $longest = max( $longest, $current );
            } else {
                $current = 0;
            }
        }
        return $longest;
    }

}
