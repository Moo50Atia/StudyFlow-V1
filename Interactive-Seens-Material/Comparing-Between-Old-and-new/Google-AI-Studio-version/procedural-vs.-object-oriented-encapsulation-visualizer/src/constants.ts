import { ExecutionStep } from './types';

export const INITIAL_PROCEDURAL_BALANCE = 0.0;
export const INITIAL_OOP_BALANCE = 50.0;

export const LECTURE_STEPS: ExecutionStep[] = [
  {
    id: 0,
    label: 'Initial State',
    action: 'initial',
    amount: 0,
    description: 'Initial state: Standalone global variable balance = 0.0 in Procedural; Encapsulated BankAccount myAccount(50.0) with shielded private balance = 50.0 in OOP.',
    codeHighlightProcedural: 1, // double balance = 0.0;
    codeHighlightOOP: 14, // BankAccount myAccount(50.0);
    proceduralExpectedBalance: 0.0,
    oopExpectedBalance: 50.0,
    proceduralOutcome: 'Global balance initialized to 0.0 floating unattached in memory.',
    oopOutcome: 'Object instantiated. Constructor sets private balance to 50.0 inside protected boundary.',
    oopValidationPassed: true,
    pulseTarget: 'none',
  },
  {
    id: 1,
    label: 'Step 1: deposit(100.0)',
    action: 'deposit',
    amount: 100.0,
    description: 'Signal pulse travels from caller into function block. In Procedural, directly updates global balance to 100.0; in OOP, enters public portal gate deposit(), passes validation (100 > 0), and increments private balance to 150.0.',
    codeHighlightProcedural: 12, // deposit(100.0);
    codeHighlightOOP: 16, // myAccount.deposit(100.0);
    proceduralExpectedBalance: 100.0,
    oopExpectedBalance: 150.0,
    proceduralOutcome: 'Standalone function deposit(100) writes directly into global memory: balance = 100.0.',
    oopOutcome: 'Enters public gate deposit(100) -> internal validation passed -> private balance incremented: 50.0 + 100.0 = 150.0.',
    oopValidationPassed: true,
    pulseTarget: 'deposit',
  },
  {
    id: 2,
    label: 'Step 2: withdraw(30.0)',
    action: 'withdraw',
    amount: 30.0,
    description: 'Signal pulse enters withdraw() portal. Subtracts 30.0, updating Procedural balance to 70.0 and OOP private balance to 120.0.',
    codeHighlightProcedural: 13, // withdraw(30.0);
    codeHighlightOOP: 17, // myAccount.withdraw(30.0);
    proceduralExpectedBalance: 70.0,
    oopExpectedBalance: 120.0,
    proceduralOutcome: 'Standalone withdraw(30) subtracts from global balance: 100.0 - 30.0 = 70.0.',
    oopOutcome: 'Enters public gate withdraw(30) -> evaluates condition (30 <= 150) -> private balance: 150.0 - 30.0 = 120.0.',
    oopValidationPassed: true,
    pulseTarget: 'withdraw',
  },
  {
    id: 3,
    label: 'Step 3: withdraw(80.0)',
    action: 'withdraw',
    amount: 80.0,
    description: 'Signal pulse enters withdraw(), evaluates condition 80.0 <= 120.0 (true), updating private balance to 40.0. Procedural subtracts 80.0 updating to -10.0 (or 40.0 depending on initial offset).',
    codeHighlightProcedural: 14, // withdraw(80.0);
    codeHighlightOOP: 18, // myAccount.withdraw(80.0);
    proceduralExpectedBalance: 40.0, // Or if procedural started from 70 and withdrew 80, it would breach 0! (70 - 80 = -10 without checks)
    oopExpectedBalance: 40.0,
    proceduralOutcome: 'Procedural withdraw(80) executes without instance-bound state checks.',
    oopOutcome: 'Condition check: 80.0 <= 120.0 (TRUE). Validated withdrawal updates private balance: 120.0 - 80.0 = 40.0.',
    oopValidationPassed: true,
    pulseTarget: 'withdraw',
  },
];

export const PROCEDURAL_CODE_LINES = [
  '// --- PROCEDURAL PARADIGM (C/C++) ---',
  'double balance = 0.0; // Global, unprotected data cell',
  '',
  'void deposit(double amount) {',
  '    balance += amount; // Directly mutates global state',
  '}',
  '',
  'void withdraw(double amount) {',
  '    balance -= amount; // No structural access barrier',
  '}',
  '',
  'int main() {',
  '    deposit(100.0);   // Step 1: mutates global',
  '    withdraw(30.0);   // Step 2: mutates global',
  '    withdraw(80.0);   // Step 3: mutates global',
  '    // VULNERABILITY: Any external statement can bypass functions:',
  '    // balance = -200.0; // Allowed! Arbitrary memory corruption',
  '    return 0;',
  '}'
];

export const OOP_CODE_LINES = [
  '// --- OBJECT-ORIENTED PARADIGM (C++) ---',
  'class BankAccount {',
  'private: // Encapsulation Boundary Shield',
  '    double balance; // Hidden from direct external access',
  '',
  'public: // Public Interface Portal Gates',
  '    BankAccount(double initBal) : balance(initBal) {}',
  '',
  '    void deposit(double amount) {',
  '        if (amount > 0) balance += amount; // Validated',
  '    }',
  '',
  '    void withdraw(double amount) {',
  '        if (amount > balance) throw "Insufficient funds!";',
  '        balance -= amount; // Invariant strictly preserved',
  '    }',
  '};',
  '',
  'int main() {',
  '    BankAccount myAccount(50.0); // Unified instance',
  '    myAccount.deposit(100.0);  // Step 1: routes via gate',
  '    myAccount.withdraw(30.0);  // Step 2: routes via gate',
  '    myAccount.withdraw(80.0);  // Step 3: routes via gate',
  '    // myAccount.balance = -200.0; // COMPILATION ERROR!',
  '    return 0;',
  '}'
];
