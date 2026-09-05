import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { describe, expect, it, vi } from 'vitest';

import CannedResponsePicker, { insertAtCursor } from './CannedResponsePicker';

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

const mockedAxios = vi.mocked(axios);

describe('insertAtCursor', () => {
  it('inserts at the caret and positions the caret after the inserted text', () => {
    expect(insertAtCursor('Hello world', 'brave ', 6, 6)).toEqual({
      value: 'Hello brave world',
      cursor: 12,
    });
  });

  it('replaces the selected text and clamps invalid selection bounds', () => {
    expect(insertAtCursor('Hello world', 'team', 6, 11)).toEqual({
      value: 'Hello team',
      cursor: 10,
    });
    expect(insertAtCursor('Hello', '!', -5, 50)).toEqual({
      value: '!',
      cursor: 1,
    });
  });
});

describe('CannedResponsePicker', () => {
  it('supports keyboard selection and inserts without submitting the surrounding reply form', async () => {
    mockedAxios.get.mockResolvedValue({
      data: {
        canned_responses: [
          { id: 'one', title: 'First response', body: 'First preview' },
          { id: 'two', title: 'Second response', body: 'Second preview' },
        ],
      },
    });
    mockedAxios.post.mockResolvedValue({ data: { body: 'Rendered second response' } });
    const onInsert = vi.fn();
    const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());

    render(
      <form onSubmit={onSubmit}>
        <CannedResponsePicker ticketId="ticket-7" onInsert={onInsert} />
      </form>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Choose a canned response' }));

    const search = await screen.findByRole('combobox', { name: 'Search canned responses' });
    await waitFor(() => expect(mockedAxios.get).toHaveBeenCalledWith(
      '/agent/tickets/ticket-7/canned-responses',
      { params: { search: '' } },
    ));
    expect((await screen.findAllByText('First preview')).length).toBe(2);

    fireEvent.keyDown(search, { key: 'ArrowDown' });
    expect(screen.getByRole('option', { name: /Second response/ })).toHaveAttribute('aria-selected', 'true');
    fireEvent.keyDown(search, { key: 'Enter' });

    await waitFor(() => expect(mockedAxios.post).toHaveBeenCalledWith(
      '/agent/tickets/ticket-7/canned-responses/two/render',
    ));
    expect(onInsert).toHaveBeenCalledWith('Rendered second response');
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
