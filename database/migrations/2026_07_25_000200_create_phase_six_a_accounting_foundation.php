<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('stable_code', 50);
            $table->string('version', 30);
            $table->string('name_fr');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('official_title');
            $table->string('issuing_authority');
            $table->string('publication_reference');
            $table->date('publication_date');
            $table->date('effective_date')->nullable();
            $table->string('source_url', 1000);
            $table->text('import_notes')->nullable();
            $table->string('review_status', 40)->default('pending_professional_review');
            $table->foreignId('superseded_by_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stable_code', 'version'], 'acct_fw_code_version_uq');
            $table->foreign('superseded_by_id', 'acct_fw_successor_fk')->references('id')->on('accounting_frameworks')->restrictOnDelete();
        });

        Schema::create('accounting_account_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_framework_id')->constrained('accounting_frameworks')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 40);
            $table->string('label_fr');
            $table->string('label_ar')->nullable();
            $table->string('normal_balance', 10);
            $table->string('account_class', 20);
            $table->boolean('posting_allowed')->default(true);
            $table->boolean('tenant_subaccounts_allowed')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['accounting_framework_id', 'code'], 'acct_tpl_fw_code_uq');
            $table->foreign('parent_id', 'acct_tpl_parent_fk')->references('id')->on('accounting_account_templates')->restrictOnDelete();
        });

        Schema::create('accounting_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_framework_id')->constrained('accounting_frameworks')->restrictOnDelete();
            $table->string('selected_regime', 30);
            $table->date('effective_date');
            $table->string('review_status', 40)->default('pending_professional_review');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('residence_id', 'acct_book_residence_uq');
            $table->index(['organization_id', 'residence_id'], 'acct_book_tenant_idx');
        });

        Schema::create('accounting_regime_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->nullable()->constrained('financial_exercises')->restrictOnDelete();
            $table->string('recommended_regime', 30);
            $table->json('inputs');
            $table->json('reason_codes');
            $table->string('rule_version', 50);
            $table->json('explanation_fr');
            $table->json('explanation_ar');
            $table->string('review_status', 40)->default('pending_professional_review');
            $table->dateTime('assessed_at');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['accounting_book_id', 'financial_exercise_id'], 'acct_assess_book_year_idx');
        });

        Schema::table('financial_exercises', function (Blueprint $table) {
            $table->string('reference', 40)->nullable()->after('residence_id');
            $table->foreignId('accounting_book_id')->nullable()->after('reference')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('accounting_framework_id')->nullable()->after('accounting_book_id')->constrained('accounting_frameworks')->restrictOnDelete();
            $table->foreignId('accounting_regime_assessment_id')->nullable()->after('accounting_framework_id')->constrained('accounting_regime_assessments')->restrictOnDelete();
            $table->string('accounting_regime', 30)->nullable()->after('accounting_regime_assessment_id');
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['residence_id', 'reference'], 'fin_ex_res_reference_idx');
        });
        if (DB::getDriverName() === 'sqlite') {
            // SQLite table rebuilds do not preserve partial-index predicates.
            DB::statement('DROP INDEX IF EXISTS financial_exercises_one_open');
            DB::statement("CREATE UNIQUE INDEX financial_exercises_one_open ON financial_exercises(residence_id) WHERE status = 'open'");
        }

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained('financial_exercises')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->text('lock_reason')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->timestamps();
            $table->unique(['financial_exercise_id', 'sequence'], 'acct_period_year_seq_uq');
            $table->unique(['financial_exercise_id', 'starts_on', 'ends_on'], 'acct_period_year_dates_uq');
            $table->index(['residence_id', 'status', 'starts_on'], 'acct_period_res_status_idx');
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('accounting_framework_id')->constrained('accounting_frameworks')->restrictOnDelete();
            $table->foreignId('template_account_id')->nullable()->constrained('accounting_account_templates')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label_fr');
            $table->string('label_ar')->nullable();
            $table->string('normal_balance', 10);
            $table->string('account_class', 20);
            $table->boolean('posting_allowed')->default(true);
            $table->boolean('reconciliation_required')->default(false);
            $table->boolean('active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'code'], 'ledger_account_book_code_uq');
            $table->foreign('parent_id', 'ledger_account_parent_fk')->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->index(['organization_id', 'residence_id', 'active'], 'ledger_account_tenant_idx');
        });

        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('label_fr');
            $table->string('label_ar')->nullable();
            $table->string('type', 30);
            $table->boolean('active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'code'], 'acct_journal_book_code_uq');
        });

        Schema::create('accounting_journal_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained('financial_exercises')->restrictOnDelete();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['accounting_journal_id', 'financial_exercise_id'], 'acct_journal_year_seq_uq');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('financial_exercise_id')->constrained('financial_exercises')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('accounting_journal_id')->constrained('accounting_journals')->restrictOnDelete();
            $table->string('entry_number', 60)->nullable();
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->text('description_fr');
            $table->text('description_ar')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('posting_key', 191)->nullable();
            $table->foreignId('reversal_of_id')->nullable();
            $table->foreignId('reversed_by_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('reversed_by_actor')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->char('posting_fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['accounting_journal_id', 'financial_exercise_id', 'entry_number'], 'journal_entry_number_uq');
            $table->unique(['accounting_book_id', 'posting_key'], 'journal_entry_posting_key_uq');
            $table->foreign('reversal_of_id', 'journal_entry_reversal_of_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('reversed_by_id', 'journal_entry_reversed_by_fk')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->index(['organization_id', 'residence_id', 'status', 'entry_date'], 'journal_entry_tenant_idx');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('account_code_snapshot', 40)->nullable();
            $table->string('account_label_snapshot')->nullable();
            $table->string('label');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['journal_entry_id', 'sequence'], 'journal_line_entry_seq_uq');
            $table->index(['ledger_account_id', 'journal_entry_id'], 'journal_line_account_idx');
        });

        Schema::create('accounting_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('residence_id')->constrained()->restrictOnDelete();
            $table->string('record_type', 100);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('before_evidence')->nullable();
            $table->json('after_evidence')->nullable();
            $table->string('context', 100)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['organization_id', 'residence_id', 'occurred_at'], 'acct_activity_tenant_idx');
            $table->index(['record_type', 'record_id'], 'acct_activity_record_idx');
        });

        $frameworkId = DB::table('accounting_frameworks')->insertGetId([
            'stable_code' => 'MA-SYNDIC-2-23-700',
            'version' => 'BO-7466-2025',
            'name_fr' => 'Plan comptable spécifique aux syndicats des copropriétaires',
            'name_ar' => 'المخطط المحاسبي الخاص باتحادات الملاك المشتركين',
            'description' => 'Annexe 2 du décret n° 2-23-700. Publication technique soumise à validation par un comptable marocain et un conseil juridique.',
            'status' => 'active',
            'official_title' => 'Décret n° 2-23-700 du 22 rejeb 1446 (23 janvier 2025) fixant les règles comptables spécifiques aux syndicats des copropriétaires',
            'issuing_authority' => 'Royaume du Maroc - Secrétariat Général du Gouvernement',
            'publication_reference' => 'Bulletin officiel n° 7466, édition de traduction officielle, 18 décembre 2025, pages 3920-3939',
            'publication_date' => '2025-12-18',
            'effective_date' => '2026-01-01',
            'source_url' => 'https://www.sgg.gov.ma/BO/FR/2873/2026/BO_7466_fr.pdf',
            'import_notes' => 'Nomenclature transcrite depuis l’annexe 2. Libellés arabes non publiés dans cette édition française; revue professionnelle requise.',
            'review_status' => 'pending_professional_review',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accounts = [
            ['1111', 'Réserves pour dépenses imprévues', 'credit', '1'], ['1112', 'Réserves pour dépenses prévues à long terme', 'credit', '1'],
            ['1191', 'Résultat (Excédent)', 'credit', '1'], ['1199', 'Résultat (Déficit)', 'debit', '1'], ['1311', 'Subventions reçues', 'credit', '1'],
            ['1511', 'Provisions pour travaux décidés', 'credit', '1'], ['1512', 'Provisions pour litiges', 'credit', '1'], ['1513', 'Provisions pour risques', 'credit', '1'], ['1514', 'Provisions pour charges', 'credit', '1'],
            ['3411', 'Fournisseurs débiteurs', 'debit', '3'], ['3412', 'Fournisseurs, avances sur travaux', 'debit', '3'], ['3413', 'Autres avances', 'debit', '3'],
            ['3421', 'Copropriétaire individualisé', 'debit', '3'], ['3422', 'Copropriétaire - budget prévisionnel', 'debit', '3'], ['3423', 'Copropriétaire - travaux et opérations non courants', 'debit', '3'], ['3424', 'Copropriétaire - créances douteuses', 'debit', '3'],
            ['3451', 'Etat et autres organismes - subventions à recevoir', 'debit', '3'], ['3452', 'Etat et autres organismes débiteurs', 'debit', '3'], ['3481', 'Débiteurs divers', 'debit', '3'], ['3491', 'Charges constatées d’avance', 'debit', '3'], ['3497', 'Comptes transitoires ou d’attente - débiteurs', 'debit', '3'], ['3942', 'Provisions pour dépréciation des comptes des copropriétaires', 'credit', '3'], ['3943', 'Provisions pour dépréciation des comptes autres que des copropriétaires', 'credit', '3'],
            ['4411', 'Fournisseurs', 'credit', '4'], ['4412', 'Fournisseurs, factures non parvenues', 'credit', '4'], ['4413', 'Autres fournisseurs', 'credit', '4'], ['4421', 'Copropriétaire - avances', 'credit', '4'], ['4431', 'Rémunérations dues', 'credit', '4'], ['4441', 'Sécurité sociale', 'credit', '4'], ['4442', 'Autres organismes sociaux', 'credit', '4'], ['4452', 'Etat - impôts et versements assimilés', 'credit', '4'], ['4453', 'Etat et autres organismes créditeurs', 'credit', '4'], ['4481', 'Créditeurs divers', 'credit', '4'], ['4491', 'Compte en attente d’imputation divers - créditeur', 'credit', '4'], ['4492', 'Compte de produits encaissés d’avance', 'credit', '4'], ['4497', 'Comptes transitoires ou d’attente - créditeurs', 'credit', '4'],
            ['5111', 'Compte à terme', 'debit', '5'], ['5112', 'Autres comptes', 'debit', '5'], ['5121', 'Banques', 'debit', '5'], ['5122', 'Chèques', 'debit', '5'], ['5161', 'Caisse', 'debit', '5'], ['5541', 'Banque (solde créditeur)', 'credit', '5'],
            ['6111', 'Eau (compteur général)', 'debit', '6'], ['6112', 'Electricité', 'debit', '6'], ['6113', 'Chauffage, énergie et combustibles', 'debit', '6'], ['6114', 'Achats produits d’entretien et petits équipements', 'debit', '6'], ['6115', 'Petit matériel', 'debit', '6'], ['6116', 'Fournitures', 'debit', '6'], ['6121', 'Remboursement d’emprunts', 'debit', '6'], ['6131', 'Nettoyage des locaux', 'debit', '6'], ['6132', 'Locations immobilières', 'debit', '6'], ['6133', 'Locations mobilières', 'debit', '6'], ['6134', 'Contrats de maintenance', 'debit', '6'], ['6135', 'Entretien et petites réparations', 'debit', '6'], ['6136', 'Primes d’assurances', 'debit', '6'], ['6137', 'Rémunérations du syndic sur gestion de la copropriété', 'debit', '6'], ['6138', 'Autres rémunérations', 'debit', '6'], ['6140', 'Frais postaux', 'debit', '6'], ['6141', 'Frais bancaires', 'debit', '6'], ['6142', 'Honoraires', 'debit', '6'], ['6143', 'Autres charges', 'debit', '6'], ['6144', 'Charges d’intérêts', 'debit', '6'], ['6161', 'Impôts et taxes', 'debit', '6'], ['6171', 'Salaires', 'debit', '6'], ['6172', 'Charges sociales et organismes sociaux', 'debit', '6'], ['6173', 'Autres frais (médecine du travail, mutuelles, etc.)', 'debit', '6'], ['6174', 'Assurance accident de travail', 'debit', '6'], ['6511', 'Travaux décidés par l’assemblée générale', 'debit', '6'], ['6512', 'Travaux urgents', 'debit', '6'], ['6513', 'Etudes techniques, diagnostic, consultation', 'debit', '6'], ['6514', 'Pertes sur créances irrécouvrables', 'debit', '6'], ['6515', 'Charges non courantes', 'debit', '6'], ['691', 'Dotations aux dépréciations sur créances douteuses', 'debit', '6'],
            ['7111', 'Provisions sur opérations courantes', 'credit', '7'], ['7112', 'Provisions sur travaux', 'credit', '7'], ['7113', 'Avances', 'credit', '7'], ['7121', 'Emprunts', 'credit', '7'], ['7122', 'Subventions', 'credit', '7'], ['7123', 'Indemnités d’assurance', 'credit', '7'], ['7124', 'Produits divers', 'credit', '7'], ['7125', 'Produits financiers', 'credit', '7'], ['7511', 'Autres produits décidés par l’assemblée générale', 'credit', '7'], ['7512', 'Produits de cession reçus', 'credit', '7'], ['7513', 'Dons reçus', 'credit', '7'], ['7514', 'Rentrées sur créances soldées', 'credit', '7'], ['7515', 'Autres produits non courants', 'credit', '7'], ['791', 'Reprises de dépréciations sur créances douteuses', 'credit', '7'],
        ];
        foreach ($accounts as $sort => [$code, $label, $normal, $class]) {
            DB::table('accounting_account_templates')->insert([
                'accounting_framework_id' => $frameworkId, 'code' => $code, 'label_fr' => $label,
                'normal_balance' => $normal, 'account_class' => $class, 'posting_allowed' => true,
                'tenant_subaccounts_allowed' => true, 'sort_order' => $sort + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_activity_events');
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_journal_sequences');
        Schema::dropIfExists('accounting_journals');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('accounting_periods');
        Schema::table('financial_exercises', function (Blueprint $table) {
            $table->dropForeign(['accounting_regime_assessment_id']);
            $table->dropForeign(['accounting_framework_id']);
            $table->dropForeign(['accounting_book_id']);
            $table->dropForeign(['locked_by']);
            $table->dropIndex('fin_ex_res_reference_idx');
            $table->dropColumn(['reference', 'accounting_book_id', 'accounting_framework_id', 'accounting_regime_assessment_id', 'accounting_regime', 'locked_at', 'locked_by']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS financial_exercises_one_open');
            DB::statement("CREATE UNIQUE INDEX financial_exercises_one_open ON financial_exercises(residence_id) WHERE status = 'open'");
        }
        Schema::dropIfExists('accounting_regime_assessments');
        Schema::dropIfExists('accounting_books');
        Schema::dropIfExists('accounting_account_templates');
        Schema::dropIfExists('accounting_frameworks');
    }
};
