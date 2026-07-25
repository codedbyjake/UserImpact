<?php

namespace MediaWiki\Extension\UserImpact;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Utils\MWTimestamp;

class Hooks {

    public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
        if ( !$out->getConfig()->get( 'UserImpactEnabled' ) ) {
            return;
        }

        $title = $out->getTitle();
        if ( !$title || !$title->inNamespace( NS_USER ) || $title->isSubpage() ) {
            return;
        }

        $viewer = $out->getUser();
        if ( !$viewer->isRegistered() || $viewer->getName() !== $title->getText() ) {
            return;
        }

        $userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
        if ( !$userOptionsLookup->getBoolOption( $viewer, 'userimpact-show' ) ) {
            return;
        }

        $stats = new ImpactStats();
        $editStats = $stats->getEditStats( User::newFromIdentity( $viewer ) );
        $viewStats = $stats->getViewStats( User::newFromIdentity( $viewer ) );

        $out->addModuleStyles( [ 'ext.userimpact.styles' ] );
        $out->addHTML( self::renderPanel( $out, $editStats, $viewStats ) );
    }

    public static function onGetPreferences( User $user, array &$preferences ): void {
        $currentValue = MediaWikiServices::getInstance()->getUserOptionsLookup()
            ->getBoolOption( $user, 'userimpact-show' );

        $preferences['userimpact-show'] = [
            'type' => 'toggle',
            'label-message' => 'userimpact-preference-show',
            'section' => 'personal/userimpact',
            'default' => $currentValue,
        ];
    }

    public static function onUserGetDefaultOptions( array &$defaultOptions ): void {
        $defaultOptions['userimpact-show'] =
            MediaWikiServices::getInstance()->getMainConfig()->get( 'UserImpactDefaultShow' );
    }

    public static function onPreferencesGetLegend( HTMLForm $form, string $key, string &$legend ): void {
        if ( $key !== 'userimpact' ) {
            return;
        }

        $heading = MediaWikiServices::getInstance()->getMainConfig()->get( 'UserImpactPreferencesHeading' );
        if ( $heading !== '' ) {
            $legend = $heading;
        }
    }

    private static function renderPanel( OutputPage $out, array $editStats, ?array $viewStats ): string {
        $viewerUser = $out->getUser();

        $lastEdited = $editStats['lastEdited']
            ? $out->getLanguage()->getHumanTimestamp(
                new MWTimestamp( $editStats['lastEdited'] ),
                null,
                $viewerUser
            )
            : wfMessage( 'userimpact-no-edits' )->text();

        $tiles = Html::rawElement( 'div', [ 'class' => 'userimpact-tiles' ],
            self::renderTile( 'userimpact-total-edits', (string)$editStats['totalEdits'] ) .
            self::renderTile( 'userimpact-last-edited', $lastEdited ) .
            self::renderTile( 'userimpact-streak', wfMessage( 'userimpact-days', $editStats['streak'] )->text() )
        );

        $periodTotal = array_sum( $editStats['dailyActivity'] );
        $chart = self::renderActivityChart( $editStats['dailyActivity'] );

        $viewsSection = '';
        if ( $viewStats !== null ) {
            $viewsSection = self::renderViewStats( $viewStats );
        }

        $chartSummary = Html::rawElement( 'div', [ 'class' => 'userimpact-chart-summary' ],
            Html::element( 'span', [ 'class' => 'userimpact-chart-number' ], (string)$periodTotal ) .
            Html::element( 'span', [ 'class' => 'userimpact-chart-number-label' ],
                wfMessage( 'userimpact-period-edits-label', $periodTotal )->text() )
        );

        $activitySection = Html::rawElement( 'div', [ 'class' => 'userimpact-activity' ],
            $chartSummary .
            $chart
        );

        return Html::rawElement( 'div', [ 'class' => 'userimpact-panel' ],
            Html::element( 'h2', [], wfMessage( 'userimpact-heading' )->text() ) .
            $tiles .
            Html::element( 'h3', [], wfMessage( 'userimpact-activity-heading' )->text() ) .
            $activitySection .
            $viewsSection
        );
    }

    private static function renderTile( string $labelMsg, string $value ): string {
        return Html::rawElement( 'div', [ 'class' => 'userimpact-tile' ],
            Html::element( 'div', [ 'class' => 'userimpact-tile-label' ], wfMessage( $labelMsg )->text() ) .
            Html::element( 'div', [ 'class' => 'userimpact-tile-value' ], $value )
        );
    }

    private static function renderActivityChart( array $dailyActivity ): string {
        $max = max( 1, ...array_values( $dailyActivity ) );
        $dates = array_keys( $dailyActivity );
        $count = count( $dates );

        $columns = '';
        foreach ( $dailyActivity as $date => $dayCount ) {
            $pct = (int)round( ( $dayCount / $max ) * 100 );
            $columns .= Html::element( 'div', [
                'class' => 'userimpact-chart-col',
                'style' => "--userimpact-pct: {$pct}%",
                'title' => "$date: $dayCount",
            ], '' );
        }

        $grid = Html::rawElement( 'div', [
            'class' => 'userimpact-chart-grid',
            'style' => "grid-template-columns: repeat( {$count}, minmax( 2px, 1fr ) );",
        ], $columns );

        $legend = Html::rawElement( 'div', [ 'class' => 'userimpact-chart-legend' ],
            Html::element( 'span', [], date( 'M j', strtotime( $dates[0] ) ) ) .
            Html::element( 'span', [], date( 'M j', strtotime( $dates[ $count - 1 ] ) ) )
        );

        return Html::rawElement( 'div', [ 'class' => 'userimpact-chart-wrap' ], $grid . $legend );
    }

    private static function renderViewStats( array $viewStats ): string {
        $items = '';
        foreach ( $viewStats['topArticles'] as $titleText => $views ) {
            $items .= Html::rawElement( 'li', [],
                Html::element( 'a', [ 'href' => Title::newFromText( $titleText )->getLocalURL() ], $titleText ) .
                ' ' . Html::element( 'span', [ 'class' => 'userimpact-views-count' ], (string)$views )
            );
        }

        return Html::rawElement( 'div', [ 'class' => 'userimpact-views' ],
            Html::element( 'h3', [], wfMessage( 'userimpact-views-heading' )->text() ) .
            Html::element( 'p', [], wfMessage( 'userimpact-total-views', $viewStats['totalViews'] )->text() ) .
            ( $items !== '' ? Html::rawElement( 'ul', [ 'class' => 'userimpact-views-list' ], $items ) : '' )
        );
    }

}
