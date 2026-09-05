import { useEffect, useId, useState } from 'react';
import axios, { AxiosError } from 'axios';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Check, LoaderCircle, MessageSquareText, Search } from 'lucide-react';

interface PickerResponse {
  id: string;
  title: string;
  body: string;
}

interface CannedResponsePickerProps {
  ticketId: string;
  onInsert: (body: string) => void;
}

export interface CursorInsertion {
  value: string;
  cursor: number;
}

export function insertAtCursor(value: string, insertion: string, start: number, end: number): CursorInsertion {
  const safeStart = Math.max(0, Math.min(start, value.length));
  const safeEnd = Math.max(safeStart, Math.min(end, value.length));
  const nextValue = `${value.slice(0, safeStart)}${insertion}${value.slice(safeEnd)}`;

  return { value: nextValue, cursor: safeStart + insertion.length };
}

export default function CannedResponsePicker({ ticketId, onInsert }: CannedResponsePickerProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [responses, setResponses] = useState<PickerResponse[]>([]);
  const [activeIndex, setActiveIndex] = useState(0);
  const [loading, setLoading] = useState(false);
  const [insertingId, setInsertingId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const listboxId = useId();

  useEffect(() => {
    if (!open) return;

    let current = true;
    const timeout = window.setTimeout(async () => {
      setLoading(true);
      setError(null);
      try {
        const result = await axios.get<{ canned_responses: PickerResponse[] }>(
          `/agent/tickets/${ticketId}/canned-responses`,
          { params: { search: query } },
        );
        if (current) {
          setResponses(result.data.canned_responses);
          setActiveIndex(0);
        }
      } catch {
        if (current) setError('Canned responses could not be loaded.');
      } finally {
        if (current) setLoading(false);
      }
    }, 150);

    return () => {
      current = false;
      window.clearTimeout(timeout);
    };
  }, [open, query, ticketId]);

  const insert = async (response: PickerResponse) => {
    setInsertingId(response.id);
    setError(null);
    try {
      const result = await axios.post<{ body: string }>(
        `/agent/tickets/${ticketId}/canned-responses/${response.id}/render`,
      );
      onInsert(result.data.body);
      setOpen(false);
      setQuery('');
    } catch (caught) {
      const requestError = caught as AxiosError<{ errors?: { body?: string[] }; message?: string }>;
      setError(requestError.response?.data.errors?.body?.[0] || 'This canned response could not be inserted.');
    } finally {
      setInsertingId(null);
    }
  };

  const activeResponse = responses[activeIndex];

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button type="button" variant="outline" size="sm" aria-label="Choose a canned response">
          <MessageSquareText className="mr-2 h-4 w-4" />Canned responses
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[min(28rem,calc(100vw-2rem))] p-0" align="start">
        <div className="border-b p-3">
          <label htmlFor={`${listboxId}-search`} className="sr-only">Search canned responses</label>
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id={`${listboxId}-search`}
              autoFocus
              role="combobox"
              aria-autocomplete="list"
              aria-controls={listboxId}
              aria-expanded={open}
              aria-activedescendant={activeResponse ? `${listboxId}-${activeResponse.id}` : undefined}
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'ArrowDown') {
                  event.preventDefault();
                  setActiveIndex((current) => Math.min(current + 1, Math.max(0, responses.length - 1)));
                } else if (event.key === 'ArrowUp') {
                  event.preventDefault();
                  setActiveIndex((current) => Math.max(0, current - 1));
                } else if (event.key === 'Enter' && activeResponse) {
                  event.preventDefault();
                  void insert(activeResponse);
                } else if (event.key === 'Escape') {
                  setOpen(false);
                }
              }}
              placeholder="Search by title or content"
              className="pl-9"
            />
          </div>
        </div>

        <div id={listboxId} role="listbox" aria-label="Available canned responses" className="max-h-56 overflow-y-auto p-1">
          {loading ? (
            <p className="flex items-center justify-center gap-2 p-6 text-sm text-muted-foreground"><LoaderCircle className="h-4 w-4 animate-spin" />Loading responses</p>
          ) : responses.length === 0 ? (
            <p className="p-6 text-center text-sm text-muted-foreground">No available responses found.</p>
          ) : responses.map((response, index) => (
            <button
              key={response.id}
              id={`${listboxId}-${response.id}`}
              type="button"
              role="option"
              aria-selected={index === activeIndex}
              className={`flex w-full items-start justify-between gap-3 rounded px-3 py-2 text-left ${index === activeIndex ? 'bg-accent' : 'hover:bg-muted'}`}
              onMouseEnter={() => setActiveIndex(index)}
              onClick={() => void insert(response)}
            >
              <span>
                <span className="block text-sm font-medium">{response.title}</span>
                <span className="mt-0.5 block line-clamp-1 text-xs text-muted-foreground">{response.body}</span>
              </span>
              {insertingId === response.id ? <LoaderCircle className="mt-0.5 h-4 w-4 animate-spin" /> : index === activeIndex ? <Check className="mt-0.5 h-4 w-4" /> : null}
            </button>
          ))}
        </div>

        {activeResponse && (
          <div className="border-t bg-muted/30 p-3">
            <p className="text-xs font-medium">Preview</p>
            <p className="mt-1 max-h-28 overflow-y-auto whitespace-pre-wrap break-words text-xs text-muted-foreground">{activeResponse.body}</p>
          </div>
        )}
        <div aria-live="polite" className="border-t px-3 py-2 text-xs text-muted-foreground">
          {error ? <span className="text-destructive">{error}</span> : `${responses.length} response${responses.length === 1 ? '' : 's'} available. Use arrow keys and Enter to insert.`}
        </div>
      </PopoverContent>
    </Popover>
  );
}
