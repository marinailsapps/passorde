// No look-alike characters (0/O, 1/l/I) so a password read off the screen is typed correctly.
const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export function generatePassword(length = 14): string {
    const values = crypto.getRandomValues(new Uint32Array(length));

    return Array.from(values, (value) => ALPHABET[value % ALPHABET.length]).join('');
}
