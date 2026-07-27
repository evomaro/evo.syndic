<?php

namespace App\Services;

use App\Models\GovernanceRuleVersion;
use Illuminate\Support\Facades\DB;

class GovernanceRuleService
{
    public function ensureVersions(): array
    {
        return DB::transaction(function () {
            $source = config('governance.legal_source');

            return collect(config('governance.rules'))->mapWithKeys(function (array $rule, string $identifier) use ($source) {
                $model = GovernanceRuleVersion::firstOrCreate(
                    ['identifier' => $identifier, 'version' => $source['version']],
                    [
                        'effective_from' => $source['effective_from'], 'official_source' => $source['official_source'],
                        'source_url' => $source['source_url'], 'review_status' => $rule['review_status'],
                        'numerator_definition' => $rule['numerator_definition'], 'denominator_definition' => $rule['denominator_definition'],
                        'threshold_numerator' => $rule['threshold_numerator'], 'threshold_denominator' => $rule['threshold_denominator'],
                        'comparison' => $rule['comparison'], 'quorum_rule' => 'first_headcount_half_gte',
                        'abstention_behavior' => $rule['abstention_behavior'], 'invalid_ballot_behavior' => 'excluded_from_numerator',
                        'second_convocation_behavior' => 'no_headcount_quorum_within_30_days',
                        'proxy_restrictions' => config('governance.proxy'),
                        'eligibility_restrictions' => ['arrears_notice' => 'statutory_text_present_counsel_review_required'],
                        'legal_payload' => ['articles' => $rule['articles'], 'source' => $source, 'application_policy' => ['remote_voting' => false]],
                        'status' => 'unverified_draft', 'confidence' => 'official_source_located',
                        'active' => false, 'voting_share_source_type' => 'legacy_financial_allocation_preview',
                    ]
                );

                return [$identifier => $model];
            })->all();
        });
    }

    public function payload(GovernanceRuleVersion $rule): array
    {
        return [
            'identifier' => $rule->identifier, 'version' => $rule->version,
            'official_source' => $rule->official_source, 'source_url' => $rule->source_url, 'review_status' => $rule->review_status,
            'status' => $rule->status, 'confidence' => $rule->confidence, 'active' => (bool) $rule->active,
            'numerator_definition' => $rule->numerator_definition, 'denominator_definition' => $rule->denominator_definition,
            'threshold_numerator' => (int) $rule->threshold_numerator, 'threshold_denominator' => (int) $rule->threshold_denominator,
            'comparison' => $rule->comparison, 'quorum_rule' => $rule->quorum_rule,
            'abstention_behavior' => $rule->abstention_behavior, 'invalid_ballot_behavior' => $rule->invalid_ballot_behavior,
            'second_convocation_behavior' => $rule->second_convocation_behavior,
            'proxy_restrictions' => $rule->proxy_restrictions, 'eligibility_restrictions' => $rule->eligibility_restrictions,
            'legal_payload' => $rule->legal_payload,
            'legal_verification' => $rule->status === 'active' && in_array($rule->confidence, ['professionally_reviewed', 'counsel_reviewed'], true) ? 'reviewed_configuration' : 'unverified_technical_preview',
        ];
    }
}
