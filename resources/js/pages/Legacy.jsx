import React, { useMemo } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const titles = {
  'auth.login':'Sign in', 'auth.register':'Create your account', 'auth.forgot-password':'Reset your password',
  'auth.reset-password':'Choose a new password', 'auth.confirm-password':'Confirm your password', 'auth.verify-email':'Verify your email',
  'subjects.index':'Subjects', 'subjects.create':'Create a subject', 'subjects.edit':'Edit subject', 'subjects.show':'Subject details',
  'lectures.index':'Lectures', 'lectures.create':'Create a lecture', 'lectures.edit':'Edit lecture', 'lectures.show':'Lecture details',
  'sections.index':'Lesson sections', 'sections.create':'Create a section', 'sections.edit':'Edit section', 'sections.show':'Section details',
  'questions.index':'Questions', 'questions.create':'Create a question', 'questions.edit':'Edit question', 'questions.show':'Question details',
  'exam_questions.index':'Exam questions', 'exam_questions.create':'Create an exam question', 'exam_questions.edit':'Edit exam question', 'exam_questions.show':'Exam question details',
  'users.index':'Accounts', 'users.create':'Create account', 'users.edit':'Edit account', 'users.show':'Account details',
  'teacher_permissions.index':'Teacher access', 'teacher_permissions.create':'Grant teacher access', 'teacher_permissions.edit':'Edit teacher access', 'teacher_permissions.show':'Teacher access details',
  'teacher-study-flow.lectures':'Teaching workspace', 'profile.edit':'Profile settings',
  'v3.errors.409':'Access Conflict', 'v3.errors.403':'Access denied', 'v3.errors.404':'Resource unavailable', 'v3.errors.503':'Service unavailable',
};
const fields = {
  subjects:['name','description'], lectures:['subject_id','title','summary','pdf_path','mindmap_path'], sections:['lecture_id','title','quick_summary','core_concept','egyptian_explain','formulas','real_life','dynamic_view_link'],
  questions:['lecture_id','question_text','question_image','idea_text','hint','solution_images','solution_explanation','dynamic_view_link'], exam_questions:['lecture_id','question_image','idea','solution_image','explanation','dynamic_view_link'],
  users:['name','email','role','password','password_confirmation'], teacher_permissions:['teacher_id','subject_id','lecture_id'],
};
const singular = { subjects:'subject', lectures:'lecture', sections:'section', questions:'question', exam_questions:'examQuestion', users:'user', teacher_permissions:'teacherPermission' };
const collectionKeys = ['subjects','lectures','sections','questions','examQuestions','exam_questions','teacher_permissions','teacherPermissions','users','runs','lessons','scenes','cases','items','uploads','reviews','approvals','documents','events','history','templates'];
const resourceFor = screen => screen.split('.')[0];
const fmt = value => value == null ? '—' : typeof value === 'object' ? (Array.isArray(value) ? `${value.length} items` : value.name || value.title || value.id || JSON.stringify(value)) : String(value);

export default function Legacy(props) {
  const { screen = 'page', auth, errors = {}, flash = {} } = props;
  const title = titles[screen] || screen.split('.').map(s=>s.replaceAll('-', ' ')).join(' / ').replace(/\b\w/g,c=>c.toUpperCase());
  const role = auth?.user?.role;
  const isOwner = role==='admin' && auth?.user?.email?.toLowerCase()==='admin@test.com';
  const authPage = screen.startsWith('auth.');
  const resource = resourceFor(screen);
  const modelName = singular[resource];
  const model = modelName ? props[modelName] : null;
  const mode = screen.endsWith('.create') ? 'create' : screen.endsWith('.edit') ? 'edit' : screen.endsWith('.index') ? 'index' : 'show';
  const rows = useMemo(() => {
    for (const k of collectionKeys) {
      if (Array.isArray(props[k])) return props[k];
      if (Array.isArray(props[k]?.data)) return props[k].data;
    }
    return [];
  }, [props]);
  return <><Head title={title}/><main className="min-h-screen bg-[#f5f7f2] text-slate-900"><header className="border-b border-slate-200 bg-white"><div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-5 py-4"><Link href="/" className="text-lg font-black">EduPlatform<span className="text-teal-700">.</span></Link><nav aria-label="Main navigation" className="flex flex-wrap gap-4 text-sm">{auth?.user ? <><Link href="/dashboard">Workspace</Link>{role === 'teacher' && <><Link href="/subjects">Subjects</Link><Link href="/v3/teacher">PDF workflow</Link></>}{role === 'admin' && <>{isOwner&&<><Link href="/subjects">Learning content</Link><Link href="/users">Accounts</Link></>}<Link href="/v3/admin">Operations</Link></>}<Link href="/profile">Profile</Link><form method="post" action="/logout"><input type="hidden" name="_token" value={csrf()}/><button className="text-teal-800 underline">Sign out</button></form></> : <><Link href="/login">Sign in</Link><Link href="/register">Register</Link></>}</nav></div></header>
    <div className={`mx-auto max-w-6xl px-5 py-8 ${authPage?'max-w-xl' : ''}`}><div className="mb-6"><p className="text-xs font-bold uppercase tracking-[.16em] text-teal-800">{authPage?'Account access':role ? `${role} workspace` : 'Learning workspace'}</p><h1 className="mt-2 text-3xl font-semibold">{title}</h1>{(props.message||flash.status||flash.success) && <p role="status" className="mt-3 rounded-xl bg-teal-50 p-3 text-teal-900">{props.message||flash.status||flash.success}</p>}</div>
      {screen.startsWith('v3.errors.') ? <ErrorPage screen={screen} message={props.message}/> : authPage ? <AuthForm screen={screen} props={props} errors={errors}/> : ['create','edit'].includes(mode) && fields[resource] ? <ResourceForm screen={screen} resource={resource} model={model} props={props} errors={errors}/> : mode === 'index' ? <ListPage screen={screen} resource={resource} rows={rows} props={props}/> : <DataPage screen={screen} props={props} rows={rows}/>}
    </div></main></>;
}
function csrf(){return document.querySelector('meta[name="csrf-token"]')?.content || '';}
function AuthForm({screen,props,errors}) {
  const register=screen==='auth.register', forgot=screen==='auth.forgot-password', reset=screen==='auth.reset-password', confirm=screen==='auth.confirm-password', verify=screen==='auth.verify-email';
  const action=register?'/register':forgot?'/forgot-password':reset?'/reset-password':confirm?'/confirm-password':verify?'/email/verification-notification':'/login';
  const {data,setData,post,processing}=useForm({name:'',email:props.email||'',password:'',password_confirmation:'',token:props.token||'',remember:false});
  const submit=e=>{e.preventDefault();post(action);};
  if(verify) return <section className="rounded-2xl bg-white p-6 shadow-sm"><p className="leading-7 text-slate-600">Thanks for joining. Please verify your email address using the link we sent. You can request another verification email below.</p><form onSubmit={submit} className="mt-5"><button disabled={processing} className="rounded-full bg-teal-800 px-5 py-3 font-semibold text-white">Resend verification email</button></form></section>;
  const input=(key,label,type='text')=><label className="block text-sm font-medium">{label}<input type={type} autoComplete={key==='email'?'email':type==='password'?'current-password':'name'} value={data[key]} onChange={e=>setData(key,e.target.value)} className="mt-1 w-full rounded-xl border-slate-300"/>{(errors[key]||props.errors?.[key])&&<span className="mt-1 block text-sm text-red-700">{errors[key]||props.errors[key]}</span>}</label>;
  return <form onSubmit={submit} className="space-y-4 rounded-2xl bg-white p-6 shadow-sm">{register&&input('name','Name')}{!confirm&&input('email','Email address','email')}{reset&&<input type="hidden" name="token" value={data.token}/ >}{!forgot&&input('password','Password','password')}{(register||reset)&&input('password_confirmation','Confirm password','password')}{!register&&!forgot&&!reset&&!confirm&&<label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data.remember} onChange={e=>setData('remember',e.target.checked)}/> Remember me</label>}<button disabled={processing} className="w-full rounded-full bg-teal-800 px-5 py-3 font-semibold text-white">{register?'Create account':forgot?'Send reset link':reset?'Reset password':confirm?'Confirm password':'Sign in'}</button><div className="flex flex-wrap justify-between gap-3 text-sm text-teal-800"><Link href="/forgot-password">Forgot password?</Link><Link href={register?'/login':'/register'}>{register?'Already registered? Sign in':'Create an account'}</Link></div></form>;
}
function ResourceForm({screen,resource,model,props,errors}) {
  const update=screen.endsWith('.edit'), id=model?.id;
  const path=`/${resource.replaceAll('_','_')}`;
  const initial=Object.fromEntries(fields[resource].map(f=>[f, f.includes('image')||f.endsWith('_path') ? null : model?.[f]??'']));
  const form=useForm(initial); const {data,setData,processing}=form;
  const submit=e=>{e.preventDefault(); form.transform(payload=>update?{...payload,_method:'put'}:payload).post(update?`${path}/${id}`:path,{forceFormData:true});};
  return <form onSubmit={submit} encType="multipart/form-data" className="grid gap-4 rounded-2xl bg-white p-6 shadow-sm sm:grid-cols-2">{fields[resource].map(field=>{
    const choices=field.endsWith('_id') ? props[field.replace('_id','s')] : null;
    const isFile=field.includes('image')||field.endsWith('_path'); const multi=field==='solution_images'; const long=['description','summary','quick_summary','core_concept','egyptian_explain','formulas','real_life','question_text','idea_text','solution_explanation','idea','explanation','hint'].includes(field);
    return <label key={field} className="text-sm font-medium">{field.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}{Array.isArray(choices)?<select value={data[field]} onChange={e=>setData(field,e.target.value)} className="mt-1 w-full rounded-xl border-slate-300"><option value="">Choose…</option>{choices.map(x=><option key={x.id} value={x.id}>{x.name||x.title||x.id}</option>)}</select>:isFile?<input type="file" accept={field.endsWith('_path')?'.pdf,image/png':'image/*'} multiple={multi} onChange={e=>setData(field,multi?Array.from(e.target.files||[]):e.target.files?.[0]||null)} className="mt-1 w-full rounded-xl border border-slate-300 p-2"/>:long?<textarea value={data[field]??''} onChange={e=>setData(field,e.target.value)} rows={3} className="mt-1 w-full rounded-xl border-slate-300"/>:<input type={field.includes('password')?'password':'text'} value={data[field]??''} onChange={e=>setData(field,e.target.value)} className="mt-1 w-full rounded-xl border-slate-300"/>}{errors[field]&&<span className="mt-1 block text-red-700">{errors[field]}</span>}</label>;
  })}<div className="sm:col-span-2"><button disabled={processing} className="rounded-full bg-teal-800 px-5 py-3 font-semibold text-white">{update?'Save changes':'Create'}</button></div></form>;
}
function ListPage({screen,resource,rows,props}) {
  const paged=Object.entries(props).find(([,v])=>Array.isArray(v?.data)&&Array.isArray(v?.links))?.[1];
  return <section className="overflow-hidden rounded-2xl bg-white shadow-sm"><div className="flex items-center justify-between border-b p-5"><h2 className="font-semibold">{paged?.meta?.total ?? rows.length} records</h2>{fields[resource]&&<Link href={`/${resource}/create`} className="rounded-full bg-teal-800 px-4 py-2 text-sm font-semibold text-white">Create new</Link>}</div>{rows.length?<ul className="divide-y">{rows.map((row,i)=>{const id=row.id;const label=row.name||row.title||row.email||row.section_identity||row.lecture_title||row.lesson_title||row.display_title||`Record ${i+1}`;return <li key={id||i} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"><div><p className="font-medium">{label}</p><p className="mt-1 text-xs text-slate-500">{row.description||row.email||row.state||row.subject?.name||row.lecture?.title||''}</p></div><div className="flex gap-3 text-sm">{id&&<Link className="text-teal-800 underline" href={`/${resource}/${id}`}>Open</Link>}{fields[resource]&&id&&<Link className="text-teal-800 underline" href={`/${resource}/${id}/edit`}>Edit</Link>}</div></li>;})}</ul>:<p className="p-8 text-center text-slate-500">No records found.</p>}{paged?.links&&<nav aria-label="Pagination" className="flex flex-wrap justify-center gap-2 border-t p-4">{paged.links.map((l,i)=><Link key={i} href={l.url||'#'} preserveScroll className={`rounded-lg px-3 py-2 text-sm ${l.active?'bg-teal-800 text-white':'bg-slate-100'} ${!l.url?'pointer-events-none opacity-40':''}`} dangerouslySetInnerHTML={{__html:l.label}}/>)}</nav>}</section>;
}
function DataPage({screen,props,rows}) {
  const omitted=new Set(['auth','errors','flash','screen','request']);
  const pairs=Object.entries(props).filter(([k,v])=>!omitted.has(k)&&v!=null);
  return <div className="space-y-5">{rows.length>0&&<section className="rounded-2xl bg-white p-5 shadow-sm"><h2 className="font-semibold">Items</h2><ul className="mt-3 divide-y">{rows.map((r,i)=><li key={r.id||i} className="py-3"><span className="font-medium">{r.title||r.name||r.section_identity||r.id||`Item ${i+1}`}</span>{r.state&&<span className="ml-3 text-sm text-slate-500">{r.state}</span>}</li>)}</ul></section>}{pairs.map(([key,value])=><section key={key} className="rounded-2xl bg-white p-5 shadow-sm"><h2 className="font-semibold capitalize">{key.replaceAll('_',' ')}</h2>{Array.isArray(value)?<ul className="mt-3 divide-y">{value.slice(0,50).map((item,i)=><li key={item?.id||i} className="py-2 text-sm">{fmt(item)}</li>)}</ul>:typeof value==='object'?<div className="mt-3 grid gap-3 sm:grid-cols-2">{Object.entries(value).filter(([k,v])=>v!=null&&typeof v!=='object').slice(0,40).map(([k,v])=><div key={k} className="rounded-xl bg-slate-50 p-3"><p className="text-xs uppercase text-slate-500">{k.replaceAll('_',' ')}</p><p className="mt-1 break-words text-sm">{fmt(v)}</p></div>)}</div>:<p className="mt-2 break-words text-sm text-slate-600">{fmt(value)}</p>}</section>)}{pairs.length===0&&rows.length===0&&<p className="rounded-2xl bg-white p-6 text-slate-600">There is no information to display yet.</p>}</div>;
}
function ErrorPage({screen,message}) {
  const conflict=screen.endsWith('.409');
  return <section className={`rounded-2xl border p-6 ${conflict?'border-amber-200 bg-amber-50':'border-slate-200 bg-white'}`}><h2 className="text-xl font-semibold">{conflict?'Action Conflict (409)':'This page is unavailable'}</h2><p className="mt-2 text-sm text-slate-700">{message||'The requested page is unavailable.'}</p><div className="mt-5 flex gap-3"><button onClick={()=>location.reload()} className="rounded-full border border-slate-300 bg-white px-4 py-2">Reload Page</button><Link href="/dashboard" className="rounded-full bg-teal-800 px-4 py-2 text-white">Return to workspace</Link></div></section>;
}
