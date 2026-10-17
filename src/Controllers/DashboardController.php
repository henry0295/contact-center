<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Repositories\MessageRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    use JsonResponseTrait;

    private MessageRepository $messages;
    private CampaignRepository $campaigns;

    public function __construct(?MessageRepository $messages = null, ?CampaignRepository $campaigns = null)
    {
        $this->messages = $messages ?? new MessageRepository();
        $this->campaigns = $campaigns ?? new CampaignRepository();
    }

    public function stats(Request $request, Response $response): Response
    {
        $campaigns = $this->campaigns->all();

        $active = array_filter($campaigns, static fn (array $c): bool => in_array($c['status'], ['Scheduled', 'Sending'], true));
        $recent = array_slice($campaigns, 0, 5);

        return $this->jsonResponse($response, [
            'emailsSent'      => $this->messages->countByChannel('email', 'Sent'),
            'smsSent'         => $this->messages->countByChannel('sms', 'Sent'),
            'totalCampaigns'  => count($campaigns),
            'activeCampaigns' => count($active),
            'recentCampaigns' => array_values($recent),
        ]);
    }
}
