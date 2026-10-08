// Configuration for the three inventory document types.
export const DOC_TYPES = {
  RECEIVE: {
    title: 'Delivery / Stock In',
    subtitle: 'Record supplier deliveries and purchases. Posting adds stock, updates average cost and books the payable or payment.',
    newTitle: 'New delivery',
    basePath: '/inventory/receiving',
    perm: 'inventory.receive',
  },
  ISSUE: {
    title: 'Stock Issuance',
    subtitle: 'Issue stock out of the storeroom to the kitchen, commissary, staff meals or another branch. Posting deducts stock and charges the expense account.',
    newTitle: 'New issuance',
    basePath: '/inventory/issuance',
    perm: 'inventory.issue',
  },
  WASTE: {
    title: 'Spoilage & Wastage',
    subtitle: 'Write off spoiled, expired or damaged stock. Posting deducts stock and books it to Spoilage & Wastage expense.',
    newTitle: 'New wastage report',
    basePath: '/inventory/wastage',
    perm: 'inventory.waste',
  },
};

export const PAYMENT_MODES = [
  { value: 'credit', label: 'On credit (Accounts Payable)' },
  { value: 'cash', label: 'Paid cash (Cash on Hand)' },
  { value: 'petty_cash', label: 'Paid from petty cash' },
  { value: 'bank', label: 'Paid by bank / check' },
  { value: 'opening', label: 'Opening balance (beginning inventory)' },
];
export const PAYMENT_LABEL = Object.fromEntries(PAYMENT_MODES.map((p) => [p.value, p.label]));

export const WASTE_REASONS = ['spoilage', 'expired', 'damaged', 'preparation waste', 'customer return', 'other'];
export const ISSUE_TO = ['Kitchen', 'Bar', 'Commissary', 'Staff meal', 'Branch 2', 'Events / catering'];
