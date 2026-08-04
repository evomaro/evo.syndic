const madNumber = new Intl.NumberFormat("fr-FR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export const formatMAD = (
    amount: number | string | null | undefined,
): string => {
    const numericAmount = Number(amount ?? 0);
    return `${madNumber.format(Number.isFinite(numericAmount) ? numericAmount : 0)} MAD`;
};

export const formatMADCents = (
    cents: number | string | null | undefined,
): string => formatMAD(Number(cents ?? 0) / 100);
