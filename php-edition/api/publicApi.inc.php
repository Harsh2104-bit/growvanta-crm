<?php
declare(strict_types=1);

/**
 * Inbound field normalisation for the public ingestion API, kept in its own
 * include so it can be reused (and tested) without executing route dispatch.
 *
 * Most webhook senders speak snake_case and every ad platform names the same
 * field differently; this is the one place that knows all of it.
 */

const PUBLIC_API_ALIASES = [
    'first_name' => 'firstName', 'firstname' => 'firstName', 'fname' => 'firstName', 'name' => 'firstName',
    'last_name' => 'lastName', 'lastname' => 'lastName', 'lname' => 'lastName', 'surname' => 'lastName',
    'email_address' => 'email', 'mail' => 'email',
    'phone_number' => 'phone', 'mobile' => 'phone', 'contact_number' => 'phone', 'phone1' => 'phone',
    'alternate_phone' => 'altPhone', 'phone2' => 'altPhone',
    'company_name' => 'company', 'organisation' => 'company', 'organization' => 'company',
    'job_title' => 'jobTitle', 'designation' => 'jobTitle', 'title' => 'jobTitle',
    'zip' => 'pincode', 'zipcode' => 'pincode', 'postal_code' => 'pincode', 'pin_code' => 'pincode',
    'utm_source' => 'utmSource', 'utm_medium' => 'utmMedium', 'utm_campaign' => 'utmCampaign',
    'utm_term' => 'utmTerm', 'utm_content' => 'utmContent',
    'landing_page' => 'landingPage', 'page_url' => 'landingPage',
    'lead_source' => 'sourceId', 'source' => 'sourceId',
    'campaign_code' => 'campaignId', 'campaign' => 'campaignId',
    'expected_value' => 'expectedValue', 'deal_value' => 'expectedValue',
    // Real estate. Note `budget` maps to budgetMax, not expectedValue: on a
    // property enquiry "budget" is what the buyer will spend, which is the top
    // of their range, not a forecast of deal value.
    'budget' => 'budgetMax', 'budget_max' => 'budgetMax', 'max_budget' => 'budgetMax',
    'budget_min' => 'budgetMin', 'min_budget' => 'budgetMin',
    'project_name' => 'project', 'property' => 'project', 'property_name' => 'project',
    'configuration' => 'configuration', 'unit_type' => 'configuration', 'bhk' => 'configuration',
    'possession' => 'possessionTimeline', 'possession_timeline' => 'possessionTimeline',
    'purpose' => 'purpose', 'funding' => 'funding', 'loan' => 'funding',
    'message' => 'notes', 'comments' => 'notes', 'remarks' => 'notes', 'notes' => 'notes',
];

/** Normalises an arbitrary inbound payload into lead input. */
function public_api_normalize(array $body): array
{
    $out = [];
    foreach ($body as $rawKey => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $key = PUBLIC_API_ALIASES[strtolower((string) $rawKey)] ?? $rawKey;
        $out[$key] = $value;
    }
    // "John Doe" arriving in a single `name` field.
    if (!empty($out['firstName']) && empty($out['lastName']) && is_string($out['firstName']) && str_contains($out['firstName'], ' ')) {
        $parts = preg_split('/\s+/', trim($out['firstName']));
        $out['firstName'] = array_shift($parts);
        $out['lastName'] = implode(' ', $parts);
    }
    return $out;
}
