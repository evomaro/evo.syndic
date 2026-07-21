export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    preferred_language: "fr" | "ar";
    current_organization_id?: number | null;
    current_residence_id?: number | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
