<?php

namespace App\Support;

/**
 * The words for "this company does not hold Dot.Press" (R-50), from the reason InfoDot gives: the `access` reason
 * of a sign-in, or the `status` of this platform's row when a session is checked. An unfamiliar reason reads as
 * "not switched on", never as an error.
 */
final class EntitlementNotice
{
    /**
     * @return array{title: string, message: string}
     */
    public static function for(?string $reason, ?string $company = null): array
    {
        $who = filled($company) ? (string) $company : 'Your company';
        $kept = 'Your presentations are kept as they are.';

        return match ($reason) {
            'suspended' => [
                'title' => 'Dot.Press is suspended for your company.',
                'message' => "$who cannot open or change anything here while it is suspended. $kept Whoever manages platforms for the company can see why in InfoDot.",
            ],
            'payment_required' => [
                'title' => 'A payment is needed to keep using Dot.Press.',
                'message' => "$who has a payment outstanding for Dot.Press, so nothing here can be opened or changed until it is settled in InfoDot. $kept",
            ],
            'subscription_expired', 'trial_ended', 'expired', 'cancelled' => [
                'title' => 'Dot.Press has ended for your company.',
                'message' => "The time $who had on Dot.Press is over, so nothing here can be opened or changed. $kept It can be switched on again in InfoDot.",
            ],
            'activation_pending' => [
                'title' => 'Dot.Press is still being switched on.',
                'message' => "The request for $who has not been completed yet, so nothing here can be opened. You can follow it in InfoDot.",
            ],
            'not_permitted_for_you' => [
                'title' => 'Your company has not given you Dot.Press.',
                'message' => "$who holds Dot.Press for chosen people only, and you are not one of them yet. Ask whoever manages platforms for the company to add you in InfoDot.",
            ],
            'no_personal_access', 'company_required' => [
                'title' => 'Dot.Press needs a company.',
                'message' => 'A personal workspace does not include Dot.Press. Open it from a company that holds it, or switch it on for your company in InfoDot.',
            ],
            'temporarily_unavailable', 'not_launched' => [
                'title' => 'Dot.Press is not available right now.',
                'message' => "InfoDot is not offering Dot.Press to $who at the moment, so nothing here can be opened or changed. $kept",
            ],
            default => [
                'title' => 'Dot.Press is not switched on for your company.',
                'message' => "$who does not hold Dot.Press at the moment, so nothing here can be opened or changed. $kept Whoever manages platforms for the company can switch it on in InfoDot.",
            ],
        };
    }
}
