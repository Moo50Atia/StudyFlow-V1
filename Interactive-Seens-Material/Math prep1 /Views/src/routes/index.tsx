import React, { lazy, Suspense } from 'react';
import { createBrowserRouter, Navigate, useParams } from 'react-router-dom';
import { Shell } from '../components/layout/Shell';
import { PlaceholderSimulation } from '../components/common/PlaceholderSimulation';

const DynamicSimulation = () => {
  const { unitId, lessonId, sectionId } = useParams();
  
  const LazyComp = React.useMemo(() => lazy(async () => {
    try {
      const module = await import(`../simulations/unit${unitId}/lesson${lessonId}/Sec${sectionId}.tsx`);
      return module;
    } catch (error) {
      console.error(`Simulation component not found for Unit ${unitId} Lesson ${lessonId} Section ${sectionId}`);
      return { default: PlaceholderSimulation };
    }
  }), [unitId, lessonId, sectionId]);

  return (
    <Suspense key={`${unitId}-${lessonId}-${sectionId}`} fallback={
      <div className="flex h-full w-full items-center justify-center bg-slate-50 dark:bg-slate-950">
        <div className="w-8 h-8 border-4 border-indigo-500/30 border-t-indigo-500 rounded-full animate-spin" />
      </div>
    }>
      <LazyComp />
    </Suspense>
  );
};

export const router = createBrowserRouter([
  {
    path: '/',
    element: <Shell />,
    errorElement: <div className="p-8 text-red-500 flex items-center justify-center h-screen bg-slate-950">Error loading application layout. Check console.</div>,
    children: [
      { index: true, element: <Navigate to="/unit/1/lesson/2/section/1" replace /> },
      { 
        path: 'unit/:unitId/lesson/:lessonId/section/:sectionId',
        element: <DynamicSimulation />
      },
      { path: '*', element: <PlaceholderSimulation /> }
    ]
  }
]);
