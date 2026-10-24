import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { templateResponseSchema } from '#shared/types/site'

export const agentEndpoints = {
  'agent/template': defineEndpoint({
    path: 'agent/template',
    auth: 'optional',
    description: '依 Host 取站台模板、旗標與 customized_setting',
    request: z.object({
      domain: z.string().min(1).optional(),
    }),
    response: templateResponseSchema,
  }),
} satisfies EndpointMap
