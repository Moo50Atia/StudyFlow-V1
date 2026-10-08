import { expect, it, vi } from 'vitest';
vi.mock('../auth.js', async original => ({...await original<typeof import('../auth.js')>(), saveTokens:vi.fn()}));
import { NotebookLMClient } from '../client.js';
const tokens = {cookies:{SID:'fixture'},csrf_token:'csrf',session_id:'sid',extracted_at:1};
it('uses full-library RPC and parses its distinct shape without guessing ownership', async () => {
 const client = new NotebookLMClient(tokens);
 const execute = vi.spyOn(client as any,'execute').mockResolvedValue([
  ['nb-1','Fixture',[100,0],[200,0],'📘',[],true]
 ]);
 const result = await client.listNotebooks();
 expect(execute).toHaveBeenCalledWith('fprTef', []);
 expect(result[0]).toMatchObject({id:'nb-1',title:'Fixture'});
 expect(result[0]).not.toHaveProperty('ownership');
});
it('does not swallow unknown list shapes', async () => {
 const client = new NotebookLMClient(tokens);
 vi.spyOn(client as any,'execute').mockResolvedValue({unexpected:true});
 await expect(client.listNotebooks()).rejects.toThrow('invalid_response');
});
it('accepts a null title returned by the live full-library endpoint', async () => {
 const client = new NotebookLMClient(tokens);
 vi.spyOn(client as any,'execute').mockResolvedValue([[['nb-untitled',null,[100,0],[200,0],null,[],true]]]);
 expect(await client.listNotebooks()).toEqual([{id:'nb-untitled',title:'Untitled',emoji:null,created_at:new Date(100000).toISOString(),modified_at:new Date(200000).toISOString()}]);
});
