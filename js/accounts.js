// Accounts are created only through SIS. Compatibility exports refuse old callers.
export async function createLogin(){ throw new Error('Accounts and access are managed in SIS.'); }
export function suggestUsername(){ return ''; }
export async function availableUsername(){ return false; }
export async function usernameTaken(){ return true; }
export function generatePassword(){ throw new Error('Temporary passwords are managed in SIS.'); }
