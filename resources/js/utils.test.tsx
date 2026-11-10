import { describe, expect, it } from 'vitest';
import { idr, integer, kg, leleGroupLabel, numeric, percentage } from './utils';

describe('number formatting', () => {
  it('uses comma grouping and period decimals throughout the UI', () => {
    expect(idr(1250000)).toBe('Rp 1,250,000');
    expect(integer(1250000)).toBe('1,250,000');
    expect(kg(1234.5)).toBe('1,234.500 kg');
    expect(kg(-1234.5)).toBe('-1,234.500 kg');
    expect(numeric(1234.567, { maximumFractionDigits: 2 })).toBe('1,234.57');
    expect(percentage(12.75)).toBe('12.75%');
    expect(percentage(null)).toBe('—');
  });
});

describe('leleGroupLabel', () => {
  it('shows pond before group name and does not include the code', () => {
    expect(leleGroupLabel({ location: 'Kolam C2', name: 'Siklus 3' })).toBe('Kolam C2 - Siklus 3');
  });
});
