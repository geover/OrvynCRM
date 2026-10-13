// The password rules the server enforces (Password::min(10)->mixedCase()->numbers()), so people can see them being met.
export const PASSWORD_RULES = [
    { id: 'length', label: 'At least 10 characters', test: (p) => p.length >= 10 },
    { id: 'case', label: 'Upper and lower case letters', test: (p) => /\p{Ll}/u.test(p) && /\p{Lu}/u.test(p) },
    { id: 'number', label: 'A number', test: (p) => /\d/.test(p) },
];

export const passwordChecks = (password) => PASSWORD_RULES.map((r) => ({ ...r, ok: r.test(password ?? '') }));
export const passwordIsValid = (password) => passwordChecks(password).every((r) => r.ok);
