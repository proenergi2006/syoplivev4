<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AccurateApiService
{
    private function getSignature($datenow)
    {
        $secret = config('services.accurate.secret');

        $hmac = hash_hmac('sha256', $datenow, $secret, true);

        return base64_encode($hmac);
    }

    private function headers()
    {
        $datenow = now()->format('d/m/Y H:i:s');

        return [
            'Content-Type' => 'application/json',
            'X-Api-Timestamp' => $datenow,
            'X-Api-Signature' => $this->getSignature($datenow),
            'Authorization' => 'Bearer ' . config('services.accurate.token'),
        ];
    }

   //GET API
    public function get($url)
    {
        $response = Http::withHeaders($this->headers())
            ->get($url);

            
        return $response->json();
    }

    // POST API
    public function post($url, $data)
    {
        $response = Http::withHeaders($this->headers())
            ->post($url, $data);

        return $response->json();
    }

    // DELETE
    public function delete($url, $data = [])
    {
        $response = Http::withHeaders($this->headers())
            ->delete($url, $data);

        return $response->json();
    }

    // =========================
    // HTTP STATUS (replace get_http_status)
    // =========================
    public function status($url)
    {
        $response = Http::head($url);

        return $response->status();
    }
}