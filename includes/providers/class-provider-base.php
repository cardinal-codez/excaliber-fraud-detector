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

        if ( ! is_string( $content ) ) {
            return $this->fallback( 'Response parsing failed' );
        }

        $content = preg_replace( '/```json|```/', '', trim( $content ) );
        $result  = json_decode( trim( $content ), true );

        // Shape only — the verdict itself is validated by aifd_normalize_ai_result().
        if ( ! is_array( $result ) || ! isset( $result['risk_score'] ) ) {
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
            'reason'     => aifd_redact_secrets( $reason ) . ' (' . $this->name . ') — manual review needed',
            'source'     => 'fallback',
        );
    }
}