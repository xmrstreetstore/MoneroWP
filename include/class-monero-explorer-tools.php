<?php
/**
 * monero_explorer_tools.php
 *
 * Uses CURL to call API functions from the block explorer
 * https://xmrchain.net/
 *
 * @author Serhack
 * @author cryptochangements
 * @author mosu-forge
 *
 */

defined( 'ABSPATH' ) || exit;

class Monero_Explorer_Tools
{
    private $url;
    public function __construct($testnet = false, $custom_url = '')
    {
        $custom_url = trim($custom_url);
        $default_url = $testnet ? MONERO_GATEWAY_TESTNET_EXPLORER_URL : MONERO_GATEWAY_MAINNET_EXPLORER_URL;
        $this->url = $custom_url !== '' ? $custom_url : $default_url;
        $this->url = preg_replace("/\/+$/", "", $this->url);
    }

    private function call_api($endpoint)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->url . $endpoint,
        ));
        $data = curl_exec($curl);
        curl_close($curl);
        return json_decode($data, true);
    }

    public function get_last_block_height()
    {
        $data = $this->call_api('/api/networkinfo');
        if(isset($data['status']) && $data['status'] == 'success')
            return $data['data']['height'] - 1;
        else
            return 0;
    }

    public function getheight()
    {
        return $this->get_last_block_height();
    }

    public function get_txs_from_block($height)
    {
        $data = $this->call_api("/api/search/$height");
        if(isset($data['status']) && $data['status'] == 'success')
            return $data['data']['txs'];
        else
            return [];
    }

    public function get_outputs($address, $viewkey)
    {
        // The explorer's /api/outputsblocks endpoint requires an explicit
        // startblock/endblock range and rejects requests spanning more than
        // 5 blocks. Stay one block behind the tip returned by /api/networkinfo
        // so a block found between the two calls can't push endblock past
        // what the explorer considers the current height.
        $height = $this->get_last_block_height();
        if($height <= 0)
            return [];

        $end_block = max(0, $height - 1);
        $start_block = max(0, $end_block - 4);

        $data = $this->call_api("/api/outputsblocks?address=$address&viewkey=$viewkey&startblock=$start_block&endblock=$end_block&mempool=1");
        if(isset($data['status']) && $data['status'] == 'success')
            return $data['data']['outputs'];
        else
            return [];
    }

    // Looks up a single, already-known transaction directly by hash,
    // independent of the (max 5 block) window get_outputs() is limited to.
    private function lookup_tx($tx_hash, $address, $viewkey)
    {
        $data = $this->call_api("/api/outputs?txhash=$tx_hash&address=$address&viewkey=$viewkey&txprove=0");
        if(!isset($data['status']) || $data['status'] != 'success' || !isset($data['data']))
            return null;
        return $data['data'];
    }

    // Used to backfill the height of a transaction that was first seen in
    // the mempool and has since been mined, but whose block has scrolled
    // out of get_outputs()'s scan window before we could re-detect it
    // there. $tip_height must be the current network height as returned by
    // get_last_block_height()/getheight(). Returns 0 if the tx is still
    // unconfirmed or the lookup fails.
    public function get_tx_height($tx_hash, $address, $viewkey, $tip_height)
    {
        $data = $this->lookup_tx($tx_hash, $address, $viewkey);
        if($data === null)
            return 0;

        $confirmations = isset($data['tx_confirmations']) ? intval($data['tx_confirmations']) : 0;
        if($confirmations <= 0)
            return 0;

        return max(0, $tip_height + 1 - $confirmations);
    }

    // Independently re-verifies a transaction that get_outputs() (the
    // block-range scan) claimed matches our address/payment_id, instead of
    // trusting its reported amount/height. get_outputs() has been observed
    // to report matches for txids that don't exist on-chain at all; this
    // re-derives the match, amount, and height from the separate, per-tx
    // endpoint before anything is credited as a real payment. Returns
    // false if the transaction doesn't genuinely match, otherwise an array
    // with 'amount' (sum of matched outputs, atomic units) and 'height'
    // (0 if still unconfirmed).
    public function verify_output($tx_hash, $address, $viewkey, $tip_height)
    {
        $data = $this->lookup_tx($tx_hash, $address, $viewkey);
        if($data === null || !isset($data['outputs']))
            return false;

        $amount = 0;
        $matched = false;
        foreach($data['outputs'] as $output) {
            if(!empty($output['match'])) {
                $matched = true;
                $amount += intval($output['amount']);
            }
        }
        if(!$matched)
            return false;

        $confirmations = isset($data['tx_confirmations']) ? intval($data['tx_confirmations']) : 0;
        $height = $confirmations > 0 ? max(0, $tip_height + 1 - $confirmations) : 0;

        return array('amount' => $amount, 'height' => $height);
    }

    function get_mempool_txs()
    {
        $data = $this->call_api('/api/mempool');
        if(isset($data['status']) && $data['status'] == 'success')
            return $data['txs'];
        else
            return [];
    }

}
