import type { AuthChallenge, AuthResult, ChallengeVerifyPayload, SessionLoginPayload, SessionRegisterPayload, User } from '../../types';
export declare function useAuth(): {
    user: import("#app").Ref<User | null>;
    initialized: import("#app").Ref<boolean>;
    isAuthenticated: import("vue").ComputedRef<boolean>;
    fetchUser: () => Promise<User | null>;
    login: (payload: SessionLoginPayload) => Promise<AuthResult>;
    register: (payload: SessionRegisterPayload) => Promise<User>;
    verifyChallenge: (payload: ChallengeVerifyPayload) => Promise<User>;
    logout: () => Promise<void>;
    clear: () => void;
    refreshCsrf: () => Promise<void>;
};
export type { AuthChallenge };
