<?php
if ( ! defined( 'ABSPATH' ) ) exit;

abstract class AIFD_Provider_Base {

    protected $name = 'unknown';

    /**
     * Call the AI provider with a prompt
     * @return array {risk_score, risk_level, decision, reason, source}
     */
    abstract public function call( $prompt );

    /**
     * Parse AI JSON response — shared logic for all providers
     */
    protected function parse_response( $content ) {

        $content = trim( $content );
        $content = preg_replace( '/```json|```/', '', $content );
        $result  = json_decode( $content, true );

        if ( ! $result || ! isset( $result['risk_score'] ) ) {
            return $this->fallback( 'Response parsing failed' );
        }

        $result['source'] = $this->name;
        return $result;
    }

    /**
     * Fallback when AI fails
     */
    protected function fallback( $reason ) {
        return array(
            'risk_score' => 50,
            'risk_level' => 'MEDIUM',
            'decision'   => 'REVIEW',
            'reason'     => $reason . ' (' . $this->name . ') — manual review needed',
            'source'     => 'fallback',
        );
    }
}