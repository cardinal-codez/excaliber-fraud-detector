<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_Pinecone {

    private $api_key;
    private $host;

    public function __construct() {
        $this->api_key = get_option( 'aifd_pinecone_key', '' );
        $this->host    = rtrim( get_option( 'aifd_pinecone_host', '' ), '/' );
    }

    /**
     * Check if Pinecone is configured
     */
    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->host );
    }

    /**
     * Upsert a vector into Pinecone
     */
    public function upsert( $id, $vector, $metadata ) {

        if ( ! $this->is_configured() ) {
            return false;
        }

        $response = wp_remote_post( $this->host . '/vectors/upsert', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'vectors' => array(
                    array(
                        'id'       => $id,
                        'values'   => $vector,
                        'metadata' => $metadata,
                    ),
                ),
            )),
        ));

        if ( is_wp_error( $response ) ) {
            error_log( 'Pinecone upsert error: ' . $response->get_error_message() );
            return false;
        }

        return true;

    }

    /**
     * Query Pinecone for similar patterns
     */
    public function query( $vector, $top_k = 5, $filter = array() ) {

        if ( ! $this->is_configured() ) {
            return array();
        }

        $body = array(
            'vector'          => $vector,
            'topK'            => $top_k,
            'includeMetadata' => true,
        );

        if ( ! empty( $filter ) ) {
            $body['filter'] = $filter;
        }

        $response = wp_remote_post( $this->host . '/query', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            error_log( 'Pinecone query error: ' . $response->get_error_message() );
            return array();
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data['matches'] ?? array();

    }

    /**
     * Delete a vector by ID
     */
    public function delete( $id ) {

        if ( ! $this->is_configured() ) {
            return false;
        }

        wp_remote_post( $this->host . '/vectors/delete', array(
            'timeout' => 30,
            'headers' => array(
                'Api-Key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'ids' => array( $id ),
            )),
        ));

        return true;

    }

}