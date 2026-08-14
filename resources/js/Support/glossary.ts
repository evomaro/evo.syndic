export type GlossaryTerm =
    | "tantiemes"
    | "distribution_key"
    | "accounting_period"
    | "fund_call"
    | "ownership_share"
    | "balance"
    | "remaining"
    | "payment_status"
    | "billing_contact"
    | "assembly_minutes";

export const glossary: Record<
    GlossaryTerm,
    {
        label: { fr: string; ar: string };
        definition: { fr: string; ar: string };
    }
> = {
    tantiemes: {
        label: { fr: "Tantièmes", ar: "الحصص" },
        definition: {
            fr: "Parts attribuées à chaque lot pour calculer sa contribution aux charges communes.",
            ar: "الحصص المخصصة لكل وحدة لحساب مساهمتها في المصاريف المشتركة.",
        },
    },
    distribution_key: {
        label: { fr: "Clé de répartition", ar: "مفتاح التوزيع" },
        definition: {
            fr: "Règle qui indique comment un montant commun est partagé entre les lots.",
            ar: "قاعدة تحدد كيفية توزيع مبلغ مشترك بين الوحدات.",
        },
    },
    accounting_period: {
        label: { fr: "Exercice comptable", ar: "السنة المحاسبية" },
        definition: {
            fr: "Période, généralement annuelle, pendant laquelle les opérations financières sont regroupées.",
            ar: "فترة، غالبا سنوية، تُجمع خلالها العمليات المالية.",
        },
    },
    fund_call: {
        label: { fr: "Appel de fonds", ar: "طلب مساهمة" },
        definition: {
            fr: "Demande adressée aux copropriétaires pour financer des charges prévues.",
            ar: "طلب موجّه إلى الملاك لتمويل مصاريف مقررة.",
        },
    },
    ownership_share: {
        label: { fr: "Quote-part", ar: "نسبة الحصة" },
        definition: {
            fr: "Part d’un copropriétaire dans un lot ou dans une dépense commune.",
            ar: "نصيب مالك في وحدة أو في مصروف مشترك.",
        },
    },
    balance: {
        label: { fr: "Solde", ar: "الرصيد" },
        definition: {
            fr: "Montant disponible sur le compte après les entrées et les sorties enregistrées.",
            ar: "المبلغ المتاح في الحساب بعد الحركات الداخلة والخارجة المسجلة.",
        },
    },
    remaining: {
        label: { fr: "Reste", ar: "المتبقي" },
        definition: {
            fr: "Part de la cotisation qui n’a pas encore été encaissée.",
            ar: "الجزء من المساهمة الذي لم يتم تحصيله بعد.",
        },
    },
    payment_status: {
        label: { fr: "Statut", ar: "الحالة" },
        definition: {
            fr: "Indique si la cotisation est impayée, partiellement payée ou entièrement payée.",
            ar: "يبين ما إذا كانت المساهمة غير مدفوعة أو مدفوعة جزئيا أو بالكامل.",
        },
    },
    billing_contact: {
        label: { fr: "Contact de facturation", ar: "جهة اتصال الفوترة" },
        definition: {
            fr: "Pour un lot en indivision, la cotisation complète est rattachée au contact principal. Les quotes-parts de propriété restent visibles sur la fiche du lot.",
            ar: "عند تعدد ملاك الوحدة، تُربط المساهمة كاملة بجهة الاتصال الرئيسية، وتبقى حصص الملكية ظاهرة في صفحة الوحدة.",
        },
    },
    assembly_minutes: {
        label: { fr: "PV d’assemblée", ar: "محضر الجمع العام" },
        definition: {
            fr: "Document officiel qui consigne les échanges, votes et décisions de l’assemblée.",
            ar: "وثيقة رسمية تسجل المناقشات والتصويتات وقرارات الجمع العام.",
        },
    },
};
